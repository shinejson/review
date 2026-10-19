<?php
/**
 * Optibiz — SMS Providers Abstraction
 * Supports Hubtel, mNotify, Africa's Talking, Nalo, Twilio + manual fallback.
 */
require_once __DIR__ . '/sa_helpers.php';
require_once __DIR__ . '/monetization.php';

if (!function_exists('sms_provider_list')) {
    function sms_provider_list($conn, $enabled_only=false)
    {
        monetization_ensure_schema($conn);
        $where = $enabled_only ? " WHERE is_enabled=1" : "";
        return sa_query($conn, "SELECT * FROM sms_providers".$where." ORDER BY sort_order ASC", 'sms_providers');
    }
}
if (!function_exists('sms_provider')) {
    function sms_provider($conn, $key)
    {
        monetization_ensure_schema($conn);
        $k = $conn->real_escape_string($key);
        return sa_one($conn, "SELECT * FROM sms_providers WHERE provider_key='$k' LIMIT 1", 'sms_providers');
    }
}
if (!function_exists('sms_normalize_phone')) {
    function sms_normalize_phone($raw, $default_country='233')
    {
        $d = preg_replace('/\D+/', '', $raw);
        if (strpos($d, '00')===0) $d = substr($d, 2);
        if ($d !== '' && $d[0]==='0' && strlen($d)>=9) {
            $d = $default_country . substr($d, 1);
        }
        return $d;
    }
}
if (!function_exists('sms_send_via_provider')) {
    /**
     * Send single SMS via provider. Returns ['ok'=>bool,'provider_id'=>string,'message'=>string,'raw'=>mixed]
     */
    function sms_send_via_provider($conn, $provider_key, $to, $message, $sender_id='')
    {
        $provider = sms_provider($conn, $provider_key);
        if (!$provider && $provider_key !== 'manual') {
            return ['ok'=>false,'message'=>'Provider not found.','raw'=>[]];
        }
        $to = sms_normalize_phone($to);
        if (strlen($to) < 7) return ['ok'=>false,'message'=>'Invalid phone.','raw'=>[]];

        // Manual => just log as sent
        if ($provider_key==='manual' || empty($provider['api_key'])) {
            return ['ok'=>true,'provider_id'=>'MANUAL-'.uniqid(),'message'=>'Logged (manual provider).','raw'=>['mode'=>'manual']];
        }

        $sender = $sender_id ?: ($provider['sender_id'] ?: 'Optibiz');
        $api_url = $provider['api_url'] ?: '';
        $api_key = $provider['api_key'] ?: '';
        $api_secret = $provider['api_secret'] ?: '';

        // Provider-specific payloads
        $payload = null; $headers = [];
        switch ($provider_key) {
            case 'hubtel':
                $payload = json_encode(['From'=>$sender,'To'=>$to,'Content'=>$message]);
                $headers = ['Authorization: Basic '.base64_encode($api_key.':'.$api_secret),'Content-Type: application/json'];
                $api_url = $api_url ?: 'https://sms.hubtel.com/v1/messages/send';
                break;
            case 'mnotify':
                // mNotify quick endpoint expects key + to + msg + sender
                $api_url = 'https://api.mnotify.com/api/sms/quick?key='.$api_key.'&to='.$to.'&msg='.urlencode($message).'&sender_id='.urlencode($sender);
                $payload = null;
                break;
            case 'africastalking':
                $payload = http_build_query(['username'=>($api_key ?: 'sandbox'),'to'=>'+'.$to,'message'=>$message,'from'=>$sender]);
                $headers = ['apikey: '.$api_secret,'Content-Type: application/x-www-form-urlencoded','Accept: application/json'];
                break;
            case 'nalo':
                $payload = http_build_query(['key'=>$api_key,'msisdn'=>$to,'msg'=>$message,'sender_id'=>$sender]);
                $headers = ['Content-Type: application/x-www-form-urlencoded'];
                break;
            case 'twilio':
                // Twilio: api_key = Account SID, api_secret = Auth Token
                $payload = http_build_query(['From'=>'+'.($sender ?: '233000000000'),'To'=>'+'.$to,'Body'=>$message]);
                $headers = ['Authorization: Basic '.base64_encode($api_key.':'.$api_secret),'Content-Type: application/x-www-form-urlencoded'];
                $api_url = rtrim($api_url,'/').'/'.$api_key.'/Messages.json';
                break;
            default:
                $payload = json_encode(['to'=>$to,'from'=>$sender,'text'=>$message]);
                $headers = ['Authorization: Bearer '.$api_key,'Content-Type: application/json'];
        }

        // Execute via cURL if available
        $result = sms_http_post($api_url, $payload, $headers);
        if ($result['ok']) {
            $data = $result['data'];
            if (is_array($data) && (isset($data['status']) && strtolower($data['status'])==='success' || isset($data['Status']) && strtolower($data['Status'])==='success' || isset($data['code']) && $data['code']==0)) {
                $mid = $data['messageId'] ?? $data['id'] ?? $data['sid'] ?? uniqid('SMS');
                return ['ok'=>true,'provider_id'=>$mid,'message'=>'Delivered to provider.','raw'=>$data];
            }
            // Even if response format varies, treat HTTP 2xx as ok for manual providers
            return ['ok'=>true,'provider_id'=>uniqid('SMS-'),'message'=>'Queued with provider.','raw'=>$data];
        }
        return ['ok'=>false,'message'=>$result['error'] ?? 'Provider unreachable.','raw'=>$result['raw'] ?? ''];
    }
}

if (!function_exists('sms_http_post')) {
    function sms_http_post($url, $payload, $headers=[], $timeout=20)
    {
        if (empty($url)) return ['ok'=>false,'error'=>'No API URL configured.','raw'=>''];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_HTTPHEADER=>$headers, CURLOPT_SSL_VERIFYPEER=>true];
            if ($payload !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); }
            else { curl_setopt($ch, CURLOPT_HTTPGET, true); }
            $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
            if ($raw===false) return ['ok'=>false,'error'=>$err ?: 'Gateway unreachable.','raw'=>''];
            $decoded=json_decode($raw,true);
            if ($code>=200 && $code<300) return ['ok'=>true,'data'=>$decoded ?: $raw,'raw'=>$raw];
            return ['ok'=>false,'error'=>($decoded['message'] ?? $decoded['error'] ?? 'Provider error HTTP '.$code),'raw'=>$raw];
        }
        $ctx = stream_context_create(['http'=>['method'=>($payload!==null?'POST':'GET'),'header'=>implode("\r\n",$headers),'content'=>$payload?:'','timeout'=>$timeout,'ignore_errors'=>true]]);
        $raw=@file_get_contents($url,false,$ctx);
        $code=0; if(isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/',$http_response_header[0],$m)) $code=(int)$m[1];
        if ($raw===false) return ['ok'=>false,'error'=>'Gateway unreachable.','raw'=>''];
        $decoded=json_decode($raw,true);
        if ($code>=200 && $code<300) return ['ok'=>true,'data'=>$decoded ?: $raw,'raw'=>$raw];
        return ['ok'=>false,'error'=>'Provider error HTTP '.$code,'raw'=>$raw];
    }
}

if (!function_exists('sms_test_connection')) {
    function sms_test_connection($conn, $provider_key)
    {
        $prov = sms_provider($conn, $provider_key);
        if (!$prov) return ['ok'=>false,'message'=>'Provider not found.'];
        if (empty($prov['api_key']) && $provider_key!=='manual') return ['ok'=>false,'message'=>'API key missing.'];
        if ($provider_key==='manual') return ['ok'=>true,'message'=>'Manual mode — logs SMS for tracking.'];
        // Cheap validation: check required fields present
        $missing=[];
        if (empty($prov['sender_id'])) $missing[]='Sender ID';
        if (empty($prov['api_url'])) $missing[]='API URL';
        if ($missing) return ['ok'=>true,'message'=>'Keys present but '.implode(', ',$missing).' recommended. Test with a real SMS.'];
        return ['ok'=>true,'message'=>'Credentials present — send a test SMS to verify delivery.'];
    }
}

if (!function_exists('sms_provider_save')) {
    function sms_provider_save($conn, $key, $data)
    {
        monetization_ensure_schema($conn);
        $k=$conn->real_escape_string($key);
        $fields=['display_name','is_enabled','api_url','api_key','api_secret','sender_id','sort_order','connection_status','connection_note'];
        $sets=[];
        foreach($fields as $f) if(array_key_exists($f,$data)) {
            if($f==='is_enabled') $sets[]="$f=".(empty($data[$f])?0:1);
            elseif($f==='sort_order') $sets[]="$f=".(int)$data[$f];
            else $sets[]="$f='".$conn->real_escape_string((string)$data[$f])."'";
        }
        if(!$sets) return false;
        $exists = sa_one($conn, "SELECT id FROM sms_providers WHERE provider_key='$k' LIMIT 1",'sms_providers');
        if($exists) return (bool)@$conn->query("UPDATE sms_providers SET ".implode(',',$sets)." WHERE provider_key='$k'");
        return false;
    }
}

if (!function_exists('sms_build_review_message')) {
    function sms_build_review_message($company_name, $review_url, $template='default', $customer_name='')
    {
        $templates = [
            'default'  => "Hi {name}, thanks for choosing {business}! ⭐ Could you spare 30 seconds to rate your experience? {link} — Your feedback helps us serve you better.",
            'gh_local' => "Hello {name}! Akwaaba from {business} 🙏 Your review means a lot — please rate us here: {link} Medaase!",
            'after_delivery' => "Hi {name}, your order from {business} is delivered! 📦 How was it? Rate us in 30s: {link}",
            'after_visit'    => "Hi {name}, thanks for visiting {business} today! We'd love your feedback: {link} Reply STOP to opt out.",
            'nps'      => "Hi {name}, on a scale of 1-5, how likely are you to recommend {business}? Tap to review: {link}",
        ];
        $t = $templates[$template] ?? $templates['default'];
        $n = $customer_name ? ' '.$customer_name : '';
        return str_replace(['{name}','{business}','{link}'], [$n,$company_name,$review_url], $t);
    }
}
