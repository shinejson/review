<?php
/**
 * ============================================================
 *  Sample dataset for the PHP render harness (tools/)
 * ============================================================
 *  Mirrors tools/preview-data.js so the PHP-rendered preview and
 *  the static fallback show the same numbers.
 *
 *  Only used by tools/php/mock-db.php — never by the real app.
 */

$sa_password_hash = password_hash('superadmin123', PASSWORD_DEFAULT);
$admin_password_hash = password_hash('admin123', PASSWORD_DEFAULT);
$other_admin_hash = password_hash('tamale-solar-2026', PASSWORD_DEFAULT);

return [
    'super_admins' => [
        ['id' => 1, 'username' => 'superadmin', 'email' => 'superadmin@optibiz.com', 'password' => $sa_password_hash, 'created_at' => '2026-01-01 09:00:00', 'permissions' => null, 'is_owner' => 1],
    ],

    /* Tenant admin accounts (the admin/ panel, separate from super_admins) */
    'admins' => [
        ['id' => 1, 'tenant_id' => 18, 'username' => 'volta_admin', 'email' => 'admin@voltalogistics.com', 'password' => $admin_password_hash, 'created_at' => '2026-08-24 09:20:00'],
        ['id' => 2, 'tenant_id' => 15, 'username' => 'cocoa_admin', 'email' => 'admin@cocoacoast.gh', 'password' => $admin_password_hash, 'created_at' => '2026-07-28 09:05:00'],
        // A different password, so the suites can prove that the literal
        // 'admin123' / 'password' shortcuts sign nobody in.
        ['id' => 3, 'tenant_id' => 13, 'username' => 'tamale_admin', 'email' => 'admin@tamalesolar.com', 'password' => $other_admin_hash, 'created_at' => '2026-07-02 10:15:00'],
    ],

    'settings' => [
        ['setting_key' => 'site_name', 'setting_value' => 'Optibiz'],
        ['setting_key' => 'admin_email', 'setting_value' => 'admin@optibiz.com'],
        ['setting_key' => 'support_email', 'setting_value' => 'support@optibiz.com'],
        ['setting_key' => 'currency_symbol', 'setting_value' => '$'],
        ['setting_key' => 'ratings_per_page', 'setting_value' => '10'],
        ['setting_key' => 'trial_days', 'setting_value' => '30'],
    ],

    'subscription_plans' => [
        ['id' => 1, 'plan_name' => 'Starter', 'price' => '29.99', 'max_ratings' => 100, 'max_customers' => 10, 'status' => 'active', 'features' => "Basic analytics\nEmail support\nUp to 10 companies\n100 ratings per month"],
        ['id' => 2, 'plan_name' => 'Professional', 'price' => '79.99', 'max_ratings' => 500, 'max_customers' => 50, 'status' => 'active', 'features' => "Advanced analytics\nPriority support\nUp to 50 companies\n500 ratings per month\nCustom branding"],
        ['id' => 3, 'plan_name' => 'Enterprise', 'price' => '199.99', 'max_ratings' => 9999, 'max_customers' => 999, 'status' => 'active', 'features' => "Full analytics suite\n24/7 phone support\nUnlimited companies\nUnlimited ratings\nAPI access\nWhite label"],
    ],

    // days = whole days from today until subscription_end_date
    'tenants' => [
        ['id' => 18, 'company_name' => 'Volta Logistics', 'email' => 'billing@voltalogistics.com', 'username' => 'volta_logistics', 'phone' => '+233 24 555 0118', 'plan_id' => 3, 'plan_name' => 'Enterprise', 'subscription_price' => '199.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 120, 'created_at' => '2026-08-24 09:12:00', 'companies' => 42],
        ['id' => 17, 'company_name' => 'Harmattan Foods', 'email' => 'accounts@harmattanfoods.gh', 'username' => 'harmattan_foods', 'phone' => '+233 20 555 0117', 'plan_id' => 2, 'plan_name' => 'Professional', 'subscription_price' => '79.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 16, 'created_at' => '2026-08-11 14:40:00', 'companies' => 18],
        ['id' => 16, 'company_name' => 'Kotoka Ground Services', 'email' => 'ops@kotokaground.com', 'username' => 'kotoka_ground_services', 'phone' => '+233 30 555 0116', 'plan_id' => 2, 'plan_name' => 'Professional', 'subscription_price' => '79.99', 'subscription_status' => 'trial', 'auto_renew' => 0, 'days' => 7, 'created_at' => '2026-08-09 11:02:00', 'companies' => 6],
        ['id' => 15, 'company_name' => 'Cocoa Coast Exports', 'email' => 'finance@cocoacoast.gh', 'username' => 'cocoa_coast_exports', 'phone' => '+233 24 555 0115', 'plan_id' => 3, 'plan_name' => 'Enterprise', 'subscription_price' => '199.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 179, 'created_at' => '2026-07-28 08:55:00', 'companies' => 31],
        ['id' => 14, 'company_name' => 'Accra Dental Group', 'email' => 'hello@accradental.com', 'username' => 'accra_dental_group', 'phone' => '+233 27 555 0114', 'plan_id' => 1, 'plan_name' => 'Starter', 'subscription_price' => '29.99', 'subscription_status' => 'active', 'auto_renew' => 0, 'days' => 2, 'created_at' => '2026-07-19 16:20:00', 'companies' => 4],
        ['id' => 13, 'company_name' => 'Tamale Solar Ltd', 'email' => 'info@tamalesolar.com', 'username' => 'tamale_solar_ltd', 'phone' => '+233 25 555 0113', 'plan_id' => 2, 'plan_name' => 'Professional', 'subscription_price' => '79.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 89, 'created_at' => '2026-07-02 10:05:00', 'companies' => 12],
        ['id' => 12, 'company_name' => 'Kumasi Textiles', 'email' => 'sales@kumasitextiles.gh', 'username' => 'kumasi_textiles', 'phone' => '+233 32 555 0112', 'plan_id' => 1, 'plan_name' => 'Starter', 'subscription_price' => '29.99', 'subscription_status' => 'trial', 'auto_renew' => 0, 'days' => 20, 'created_at' => '2026-06-22 13:48:00', 'companies' => 3],
        ['id' => 11, 'company_name' => 'Cape Coast Fintech', 'email' => 'team@coastfintech.com', 'username' => 'cape_coast_fintech', 'phone' => '+233 24 555 0111', 'plan_id' => 3, 'plan_name' => 'Enterprise', 'subscription_price' => '199.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 151, 'created_at' => '2026-06-04 09:30:00', 'companies' => 27],
        ['id' => 10, 'company_name' => 'Ashanti AgriCo', 'email' => 'admin@ashantiagri.gh', 'username' => 'ashanti_agrico', 'phone' => '+233 20 555 0110', 'plan_id' => 2, 'plan_name' => 'Professional', 'subscription_price' => '79.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 43, 'created_at' => '2026-05-18 15:12:00', 'companies' => 15],
        ['id' => 9, 'company_name' => 'Tema Steel Works', 'email' => 'accounts@temasteel.com', 'username' => 'tema_steel_works', 'phone' => '+233 30 555 0109', 'plan_id' => 2, 'plan_name' => 'Professional', 'subscription_price' => '79.99', 'subscription_status' => 'inactive', 'auto_renew' => 0, 'days' => -32, 'created_at' => '2026-04-30 12:00:00', 'companies' => 9],
        ['id' => 8, 'company_name' => 'Ho Mountain Tours', 'email' => 'book@homountain.gh', 'username' => 'ho_mountain_tours', 'phone' => '+233 27 555 0108', 'plan_id' => 1, 'plan_name' => 'Starter', 'subscription_price' => '29.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 90, 'created_at' => '2026-04-12 09:44:00', 'companies' => 5],
        ['id' => 7, 'company_name' => 'Takoradi Marine', 'email' => 'ops@takoradimarine.com', 'username' => 'takoradi_marine', 'phone' => '+233 24 555 0107', 'plan_id' => 3, 'plan_name' => 'Enterprise', 'subscription_price' => '199.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 210, 'created_at' => '2026-03-27 11:18:00', 'companies' => 36],
        ['id' => 6, 'company_name' => 'Sunyani Health Partners', 'email' => 'care@sunyanihealth.gh', 'username' => 'sunyani_health_partners', 'phone' => '+233 25 555 0106', 'plan_id' => 2, 'plan_name' => 'Professional', 'subscription_price' => '79.99', 'subscription_status' => 'active', 'auto_renew' => 0, 'days' => 30, 'created_at' => '2026-03-09 08:25:00', 'companies' => 11],
        ['id' => 5, 'company_name' => 'Obuasi Mining Supplies', 'email' => 'sales@obuasisupplies.com', 'username' => 'obuasi_mining_supplies', 'phone' => '+233 32 555 0105', 'plan_id' => 1, 'plan_name' => 'Starter', 'subscription_price' => '29.99', 'subscription_status' => 'cancelled', 'auto_renew' => 0, 'days' => -64, 'created_at' => '2026-02-21 17:02:00', 'companies' => 2],
        ['id' => 4, 'company_name' => 'Ada Beach Resorts', 'email' => 'stay@adabeach.gh', 'username' => 'ada_beach_resorts', 'phone' => '+233 20 555 0104', 'plan_id' => 2, 'plan_name' => 'Professional', 'subscription_price' => '79.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 70, 'created_at' => '2026-02-02 10:35:00', 'companies' => 8],
        ['id' => 3, 'company_name' => 'Wa Shea Butter Co.', 'email' => 'hello@washea.gh', 'username' => 'wa_shea_butter_co', 'phone' => '+233 27 555 0103', 'plan_id' => 1, 'plan_name' => 'Starter', 'subscription_price' => '29.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 25, 'created_at' => '2026-01-14 14:10:00', 'companies' => 6],
        ['id' => 2, 'company_name' => 'XYZ Industries', 'email' => 'admin@xyzind.com', 'username' => 'xyz_industries', 'phone' => '555-0102', 'plan_id' => 1, 'plan_name' => 'Starter', 'subscription_price' => '29.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 152, 'created_at' => '2026-02-01 09:00:00', 'companies' => 7],
        ['id' => 1, 'company_name' => 'ABC Corporation', 'email' => 'admin@abccorp.com', 'username' => 'abc_corporation', 'phone' => '555-0101', 'plan_id' => 2, 'plan_name' => 'Professional', 'subscription_price' => '79.99', 'subscription_status' => 'active', 'auto_renew' => 1, 'days' => 120, 'created_at' => '2026-01-01 09:00:00', 'companies' => 14],
    ],

    'categories' => [
        ['id' => 1, 'name' => 'Finance', 'description' => 'Banks, insurers and fintech'],
        ['id' => 2, 'name' => 'Healthcare', 'description' => 'Clinics, labs and hospitals'],
        ['id' => 3, 'name' => 'Technology', 'description' => 'Software and IT services'],
        ['id' => 4, 'name' => 'Retail', 'description' => 'Shops, food and hospitality'],
        ['id' => 5, 'name' => 'Manufacturing', 'description' => 'Factories and processing'],
        ['id' => 6, 'name' => 'Logistics', 'description' => 'Freight, haulage and storage'],
    ],

    'quote_requests' => [
        ['id' => 9, 'company_name' => 'Bolgatanga Grain Traders', 'contact_person' => 'Amina Fuseini', 'email' => 'amina@bolgagrains.gh', 'phone' => '+233 24 555 0209', 'website' => 'bolgagrains.gh', 'location' => 'Bolgatanga, Upper East', 'category_id' => 4, 'category_name' => 'Retail', 'plan_id' => 2, 'plan_name' => 'Professional', 'num_companies' => 12, 'expected_ratings' => 300, 'notes' => 'We run 12 grain depots and want one place to collect customer feedback after every delivery.', 'status' => 'pending', 'created_at' => '2026-09-01 08:14:00'],
        ['id' => 8, 'company_name' => 'Osu Nightlife Group', 'contact_person' => 'Kwame Mensah', 'email' => 'kwame@osugroup.com', 'phone' => '+233 20 555 0208', 'website' => 'osugroup.com', 'location' => 'Accra, Greater Accra', 'category_id' => 4, 'category_name' => 'Retail', 'plan_id' => 1, 'plan_name' => 'Starter', 'num_companies' => 4, 'expected_ratings' => 80, 'notes' => 'Three restaurants and a lounge. Mostly interested in the public rating page.', 'status' => 'pending', 'created_at' => '2026-08-30 19:42:00'],
        ['id' => 7, 'company_name' => 'Northern Freight Co.', 'contact_person' => 'Issahaku Bello', 'email' => 'ops@northernfreight.gh', 'phone' => '+233 25 555 0207', 'website' => '', 'location' => 'Tamale, Northern', 'category_id' => 5, 'category_name' => 'Manufacturing', 'plan_id' => 3, 'plan_name' => 'Enterprise', 'num_companies' => 26, 'expected_ratings' => 900, 'notes' => 'Fleet of 26 trucks. Need API access to push our own NPS scores.', 'status' => 'contacted', 'created_at' => '2026-08-26 11:05:00'],
        ['id' => 6, 'company_name' => 'Elmina Fisheries', 'contact_person' => 'Grace Aidoo', 'email' => 'grace@elminafish.gh', 'phone' => '+233 27 555 0206', 'website' => 'elminafish.gh', 'location' => 'Elmina, Central', 'category_id' => 5, 'category_name' => 'Manufacturing', 'plan_id' => 1, 'plan_name' => 'Starter', 'num_companies' => 2, 'expected_ratings' => 40, 'notes' => '', 'status' => 'contacted', 'created_at' => '2026-08-21 15:33:00'],
        ['id' => 5, 'company_name' => 'Legon Biotech Labs', 'contact_person' => 'Dr. Yaw Boateng', 'email' => 'yaw@legonbiotech.com', 'phone' => '+233 24 555 0205', 'website' => 'legonbiotech.com', 'location' => 'Legon, Greater Accra', 'category_id' => 2, 'category_name' => 'Healthcare', 'plan_id' => 3, 'plan_name' => 'Enterprise', 'num_companies' => 9, 'expected_ratings' => 250, 'notes' => 'Interested in white labelling for our partner clinics.', 'status' => 'converted', 'created_at' => '2026-08-14 09:20:00'],
        ['id' => 4, 'company_name' => 'Sekondi Shipyards', 'contact_person' => 'Nana Osei', 'email' => 'nana@sekondiship.gh', 'phone' => '+233 30 555 0204', 'website' => '', 'location' => 'Sekondi-Takoradi, Western', 'category_id' => 5, 'category_name' => 'Manufacturing', 'plan_id' => 2, 'plan_name' => 'Professional', 'num_companies' => 6, 'expected_ratings' => 120, 'notes' => 'Asked for a discount on annual billing.', 'status' => 'rejected', 'created_at' => '2026-08-02 13:47:00'],
    ],

    'customers' => [
        ['id' => 51, 'tenant_id' => 18, 'company_name' => 'Volta Haulage Division', 'category_name' => 'Manufacturing', 'email' => 'ops@voltahaulage.gh', 'phone' => '+233 24 555 0301', 'whatsapp_number' => '+233 24 555 0301', 'website' => 'volta-haulage.gh', 'created_at' => '2026-08-25 10:00:00', 'rating_count' => 64, 'avg_rating' => 4.8],
        ['id' => 52, 'tenant_id' => 18, 'company_name' => 'Volta Cold Storage', 'category_name' => 'Retail', 'email' => 'ops@voltacold.gh', 'phone' => '+233 24 555 0302', 'whatsapp_number' => '024 555 0302', 'website' => 'voltacold.gh', 'created_at' => '2026-08-26 10:00:00', 'rating_count' => 41, 'avg_rating' => 4.6],
        ['id' => 53, 'tenant_id' => 18, 'company_name' => 'Volta Freight Forwarding', 'category_name' => 'Technology', 'email' => 'ops@voltafreight.com', 'phone' => '+233 24 555 0303', 'whatsapp_number' => '', 'website' => 'voltafreight.com', 'created_at' => '2026-08-27 10:00:00', 'rating_count' => 38, 'avg_rating' => 4.7],
        ['id' => 54, 'tenant_id' => 18, 'company_name' => 'Volta Warehouse Tema', 'category_name' => 'Manufacturing', 'email' => 'ops@voltawh.gh', 'phone' => '', 'website' => '', 'created_at' => '2026-08-28 10:00:00', 'rating_count' => 29, 'avg_rating' => 4.4],
        ['id' => 55, 'tenant_id' => 18, 'company_name' => 'Volta Last-Mile Accra', 'category_name' => 'Retail', 'email' => 'ops@voltalastmile.gh', 'phone' => '', 'website' => 'voltalastmile.gh', 'created_at' => '2026-08-29 10:00:00', 'rating_count' => 24, 'avg_rating' => 4.5],
        ['id' => 41, 'tenant_id' => 15, 'company_name' => 'Cocoa Coast Exports', 'category_name' => 'Manufacturing', 'email' => 'info@cocoacoast.gh', 'phone' => '', 'whatsapp_number' => '+233 20 555 0141', 'website' => 'cocoacoast.gh', 'created_at' => '2026-07-29 10:00:00', 'rating_count' => 71, 'avg_rating' => 4.9],
        ['id' => 31, 'tenant_id' => 7, 'company_name' => 'Takoradi Marine Terminal', 'category_name' => 'Manufacturing', 'email' => 'info@takoradimarine.com', 'phone' => '', 'website' => 'takoradimarine.com', 'created_at' => '2026-03-28 10:00:00', 'rating_count' => 128, 'avg_rating' => 4.8],
        ['id' => 32, 'tenant_id' => 11, 'company_name' => 'Cape Coast Fintech Hub', 'category_name' => 'Finance', 'email' => 'info@coastfintech.com', 'phone' => '', 'website' => 'coastfintech.com', 'created_at' => '2026-06-05 10:00:00', 'rating_count' => 96, 'avg_rating' => 4.7],
        ['id' => 33, 'tenant_id' => 10, 'company_name' => 'Ashanti AgriCo Depot', 'category_name' => 'Retail', 'email' => 'info@ashantiagri.gh', 'phone' => '', 'website' => '', 'created_at' => '2026-05-19 10:00:00', 'rating_count' => 58, 'avg_rating' => 4.3],
        ['id' => 34, 'tenant_id' => 13, 'company_name' => 'Tamale Solar Installers', 'category_name' => 'Technology', 'email' => 'info@tamalesolar.com', 'phone' => '', 'website' => 'tamalesolar.com', 'created_at' => '2026-07-03 10:00:00', 'rating_count' => 41, 'avg_rating' => 4.5],
    ],

    /* Full rating rows for the admin panel's ratings screen */
    'ratings' => [
        ['id' => 901, 'question_id' => null, 'company_id' => 51, 'customer_name' => 'Abena Owusu', 'customer_email' => 'abena@example.com', 'rating' => 5, 'comment' => 'Drivers were on time and the cargo tracking page is excellent.', 'created_at' => '2026-09-02 06:40:00'],
        ['id' => 900, 'question_id' => null, 'company_id' => 52, 'customer_name' => 'Kojo Antwi', 'customer_email' => 'kojo@example.com', 'rating' => 4, 'comment' => 'Good service, but the invoice arrived two days late.', 'created_at' => '2026-09-02 00:15:00'],
        ['id' => 899, 'question_id' => null, 'company_id' => 53, 'customer_name' => 'Nii Armah', 'customer_email' => 'nii@example.com', 'rating' => 5, 'comment' => 'Smooth customs clearance, will use them again.', 'created_at' => '2026-09-01 12:20:00'],
        ['id' => 898, 'question_id' => null, 'company_id' => 54, 'customer_name' => 'Grace Mensah', 'customer_email' => 'grace@example.com', 'rating' => 3, 'comment' => 'Pallets were mislabelled on arrival.', 'created_at' => '2026-08-31 09:05:00'],
        ['id' => 897, 'question_id' => null, 'company_id' => 31, 'customer_name' => 'Yaw Danso', 'customer_email' => 'yaw@example.com', 'rating' => 5, 'comment' => 'Best in the western region.', 'created_at' => '2026-08-30 16:44:00'],
        ['id' => 896, 'question_id' => null, 'company_id' => 32, 'customer_name' => 'Selorm Agbeko', 'customer_email' => 'selorm@example.com', 'rating' => 4, 'comment' => 'Onboarding took a day longer than promised.', 'created_at' => '2026-08-29 11:12:00'],
        ['id' => 895, 'question_id' => null, 'company_id' => 41, 'customer_name' => 'Adjoa Mensah', 'customer_email' => 'adjoa@example.com', 'rating' => 5, 'comment' => 'Consistent quality across every shipment.', 'created_at' => '2026-08-28 15:03:00'],
    ],

    'ratings_recent' => [
        ['id' => 901, 'rating' => 5, 'customer_name' => 'Abena Owusu', 'customer_email' => 'abena@example.com', 'comment' => 'Drivers were on time and the cargo tracking page is excellent.', 'company_name' => 'Volta Haulage Division', 'question_id' => null, 'company_id' => 51, 'created_at' => '2026-09-02 06:40:00'],
        ['id' => 900, 'rating' => 4, 'customer_name' => 'Kojo Antwi', 'customer_email' => 'kojo@example.com', 'comment' => 'Good service, but the invoice arrived two days late.', 'company_name' => 'Volta Cold Storage', 'question_id' => null, 'company_id' => 52, 'created_at' => '2026-09-02 00:15:00'],
        ['id' => 899, 'rating' => 5, 'customer_name' => 'Nii Armah', 'customer_email' => 'nii@example.com', 'comment' => 'Smooth customs clearance, will use them again.', 'company_name' => 'Volta Freight Forwarding', 'question_id' => null, 'company_id' => 53, 'created_at' => '2026-09-01 12:20:00'],
        ['id' => 898, 'rating' => 3, 'customer_name' => 'Grace Mensah', 'customer_email' => 'grace@example.com', 'comment' => 'Pallets were mislabelled on arrival.', 'company_name' => 'Volta Warehouse Tema', 'question_id' => null, 'company_id' => 54, 'created_at' => '2026-08-31 09:05:00'],
        ['id' => 897, 'rating' => 5, 'customer_name' => 'Yaw Danso', 'customer_email' => 'yaw@example.com', 'comment' => 'Best in the western region.', 'company_name' => 'Takoradi Marine Terminal', 'question_id' => null, 'company_id' => 31, 'created_at' => '2026-08-30 16:44:00'],
    ],

    // ratings collected per day (index 0 = 62 days ago … last = today)
    'ratings_per_day' => [2, 4, 1, 6, 3, 0, 0, 5, 2, 7, 4, 1, 3, 0, 1, 6, 8, 2, 4, 5, 0, 0, 3, 7, 9, 2, 1, 4, 0, 2,
                         5, 6, 3, 8, 1, 0, 0, 4, 7, 5, 2, 9, 3, 1, 6, 0, 0, 2, 8, 4, 7, 3, 5, 1, 0, 2, 9, 6, 4, 8,
                         3, 5, 11],

    /* Plan changes a workspace asked for (admin/subscription.php) */
    'subscription_requests' => [
        ['id' => 3, 'tenant_id' => 18, 'current_plan_id' => 2, 'requested_plan_id' => 3, 'direction' => 'upgrade', 'note' => 'Upgrade requested from the workspace', 'status' => 'pending', 'created_at' => '2026-09-03 09:12:00', 'resolved_at' => null, 'company_name' => 'Volta Logistics', 'email' => 'ops@voltalogistics.gh', 'current_plan_name' => 'Professional', 'current_price' => '79.99', 'requested_plan_name' => 'Enterprise', 'requested_price' => '199.99'],
        ['id' => 2, 'tenant_id' => 11, 'current_plan_id' => 1, 'requested_plan_id' => 2, 'direction' => 'upgrade', 'note' => 'Running out of monthly ratings', 'status' => 'pending', 'created_at' => '2026-09-01 16:40:00', 'resolved_at' => null, 'company_name' => 'Cape Coast Fintech', 'email' => 'admin@coastfintech.com', 'current_plan_name' => 'Starter', 'current_price' => '29.99', 'requested_plan_name' => 'Professional', 'requested_price' => '79.99'],
        ['id' => 1, 'tenant_id' => 18, 'current_plan_id' => 1, 'requested_plan_id' => 2, 'direction' => 'upgrade', 'note' => '', 'status' => 'approved', 'created_at' => '2026-06-18 11:05:00', 'resolved_at' => '2026-06-18 15:20:00', 'company_name' => 'Volta Logistics', 'email' => 'ops@voltalogistics.gh', 'current_plan_name' => 'Starter', 'current_price' => '29.99', 'requested_plan_name' => 'Professional', 'requested_price' => '79.99'],
    ],

    /* Connected networks + the posts made from reviews (admin/social.php) */
    'social_accounts' => [
        ['id' => 1, 'tenant_id' => 18, 'platform' => 'facebook', 'account_name' => 'Volta Logistics GH', 'account_ref' => '102938475610', 'access_token' => 'EAAG1234567890abcdefghijklmnop', 'status' => 'connected', 'last_error' => null, 'last_used_at' => '2026-09-02 08:10:00', 'created_at' => '2026-07-11 10:00:00'],
        ['id' => 2, 'tenant_id' => 18, 'platform' => 'linkedin', 'account_name' => 'Volta Logistics', 'account_ref' => 'urn:li:organization:8123456', 'access_token' => 'AQV1234567890abcdefghijklmnop', 'status' => 'connected', 'last_error' => null, 'last_used_at' => null, 'created_at' => '2026-08-02 10:00:00'],
    ],

    'social_posts' => [
        ['id' => 5, 'tenant_id' => 18, 'company_id' => 51, 'rating_id' => 901, 'platform' => 'facebook', 'content' => '★★★★★ Another 5-star review for Volta Haulage Division!' . "\n\n" . '“Drivers were on time and the cargo tracking page is excellent.” — Abena O.' . "\n\n" . '#VoltaHaulageDivision #CustomerReview #5StarService', 'status' => 'published', 'remote_id' => '102938475610_889900', 'remote_url' => 'https://www.facebook.com/102938475610_889900', 'error' => null, 'created_at' => '2026-09-02 08:10:00', 'published_at' => '2026-09-02 08:10:00', 'company_name' => 'Volta Haulage Division'],
        ['id' => 4, 'tenant_id' => 18, 'company_id' => 53, 'rating_id' => 899, 'platform' => 'linkedin', 'content' => 'Customer feedback we are proud of ★★★★★' . "\n\n" . '“Smooth customs clearance, will use them again.” — Nii A.', 'status' => 'draft', 'remote_id' => null, 'remote_url' => null, 'error' => null, 'created_at' => '2026-09-01 13:02:00', 'published_at' => null, 'company_name' => 'Volta Freight Forwarding'],
        ['id' => 3, 'tenant_id' => 18, 'company_id' => 52, 'rating_id' => 900, 'platform' => 'twitter', 'content' => '★★★★☆ “Good service, but the invoice arrived two days late.” — Kojo A.', 'status' => 'failed', 'remote_id' => null, 'remote_url' => null, 'error' => 'Unauthorized: the access token expired.', 'created_at' => '2026-08-30 07:45:00', 'published_at' => null, 'company_name' => 'Volta Cold Storage'],
    ],

    /* Live sign-ins (includes/session.php). 'preview-session-token' is the
       row the harness is signed in with — it must stay live and recently
       used, otherwise every rendered page would be bounced to the login
       screen. The others are the "signed in somewhere else" devices. */
    'user_sessions' => [
        ['id' => 1, 'session_token' => 'preview-session-token', 'portal' => 'superadmin', 'user_id' => 1,
         'user_label' => 'superadmin', 'user_kind' => 'super_admin', 'ip_address' => '197.251.200.9',
         'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-2 hour')), 'last_seen_at' => date('Y-m-d H:i:s'),
         'logged_out_at' => null, 'logout_reason' => null],
        ['id' => 2, 'session_token' => 'owner-tablet-token', 'portal' => 'superadmin', 'user_id' => 1,
         'user_label' => 'superadmin', 'user_kind' => 'super_admin', 'ip_address' => '41.66.202.14',
         'user_agent' => 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
         'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')), 'last_seen_at' => date('Y-m-d H:i:s', strtotime('-3 hour')),
         'logged_out_at' => null, 'logout_reason' => null],
        ['id' => 3, 'session_token' => 'preview-session-token', 'portal' => 'admin', 'user_id' => 1,
         'user_label' => 'volta_admin', 'user_kind' => 'admin', 'ip_address' => '197.251.200.9',
         'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-40 minute')), 'last_seen_at' => date('Y-m-d H:i:s'),
         'logged_out_at' => null, 'logout_reason' => null],
        ['id' => 4, 'session_token' => 'volta-office-pc-token', 'portal' => 'admin', 'user_id' => 1,
         'user_label' => 'volta_admin', 'user_kind' => 'admin', 'ip_address' => '154.160.11.72',
         'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-3 day')), 'last_seen_at' => date('Y-m-d H:i:s', strtotime('-6 hour')),
         'logged_out_at' => null, 'logout_reason' => null],
        ['id' => 5, 'session_token' => 'closed-session-token', 'portal' => 'admin', 'user_id' => 1,
         'user_label' => 'volta_admin', 'user_kind' => 'admin', 'ip_address' => '154.160.11.72',
         'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
         'created_at' => date('Y-m-d H:i:s', strtotime('-5 day')), 'last_seen_at' => date('Y-m-d H:i:s', strtotime('-2 day')),
         'logged_out_at' => date('Y-m-d H:i:s', strtotime('-2 day')), 'logout_reason' => 'user'],
    ],

    /* ============================================================
       Activity log (system_logs)
       ------------------------------------------------------------
       Rows for the three portals so both readers can be exercised:

         · tenant 18 (Volta Logistics) — the workspace the admin
           panel signs in as; mix of workspace actions and events
           the platform recorded about that workspace.
         · tenants 15 and 13 — rows that MUST NOT appear in tenant
           18's log, proving admin/logs.php is scoped.
         · tenant_id NULL — platform-wide super admin events, which
           a workspace must never see either.

       `user_kind` is not a column of system_logs; the label is what
       the UI shows.
       ============================================================ */
    'system_logs' => [
        /* --- tenant 18: workspace-side activity (portal = admin) --- */
        ['id' => 1, 'portal' => 'admin', 'tenant_id' => 18, 'user_id' => 18, 'user_label' => 'Volta Logistics',
         'action' => 'sign_in', 'description' => 'Signed in from Accra, Ghana', 'entity_type' => 'tenant', 'entity_id' => 18,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-1 day 2 hour'))],
        ['id' => 2, 'portal' => 'admin', 'tenant_id' => 18, 'user_id' => 18, 'user_label' => 'Volta Logistics',
         'action' => 'profile_update', 'description' => 'Updated the workspace phone number and support email', 'entity_type' => 'tenant', 'entity_id' => 18,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-1 day 1 hour'))],
        ['id' => 3, 'portal' => 'admin', 'tenant_id' => 18, 'user_id' => 18, 'user_label' => 'Volta Logistics',
         'action' => 'service_create', 'description' => 'Added “Nationwide Freight Tracking” to the services catalogue', 'entity_type' => 'service', 'entity_id' => 4,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-22 hour'))],
        ['id' => 4, 'portal' => 'admin', 'tenant_id' => 18, 'user_id' => 3, 'user_label' => 'Kwabena Mensah (team)',
         'action' => 'team_create', 'description' => 'Invited Akosua Boateng as Reviews Team', 'entity_type' => 'team_member', 'entity_id' => 6,
         'ip_address' => '154.160.11.72', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-18 hour'))],
        ['id' => 5, 'portal' => 'admin', 'tenant_id' => 18, 'user_id' => 3, 'user_label' => 'Kwabena Mensah (team)',
         'action' => 'review_reply', 'description' => 'Replied to the 3-star review from Kojo A. (Volta Cold Storage)', 'entity_type' => 'rating', 'entity_id' => 900,
         'ip_address' => '154.160.11.72', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-9 hour'))],
        ['id' => 6, 'portal' => 'admin', 'tenant_id' => 18, 'user_id' => 18, 'user_label' => 'Volta Logistics',
         'action' => 'plan_request', 'description' => 'Requested a plan change (Enterprise → Enterprise, annual billing)', 'entity_type' => 'subscription', 'entity_id' => 3,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-5 hour'))],
        ['id' => 7, 'portal' => 'admin', 'tenant_id' => 18, 'user_id' => 18, 'user_label' => 'Volta Logistics',
         'action' => 'backup_create', 'description' => 'Created backup workspace_18_2026-09-15_090400.json.gz (412.0 KB, 16 sections, 1,284 records)', 'entity_type' => 'backup', 'entity_id' => 1,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-4 hour'))],
        ['id' => 8, 'portal' => 'admin', 'tenant_id' => 18, 'user_id' => 18, 'user_label' => 'Volta Logistics',
         'action' => 'backup_download', 'description' => 'Downloaded backup workspace_18_2026-09-15_090400.json.gz', 'entity_type' => 'backup', 'entity_id' => 1,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-3 hour'))],
        ['id' => 9, 'portal' => 'admin', 'tenant_id' => 18, 'user_id' => 18, 'user_label' => 'Volta Logistics',
         'action' => 'auto_renew', 'description' => 'Turned automatic renewal on for the Enterprise plan', 'entity_type' => 'subscription', 'entity_id' => 3,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-95 minute'))],

        /* --- tenant 18: what the platform did to this workspace --- */
        ['id' => 10, 'portal' => 'superadmin', 'tenant_id' => 18, 'user_id' => 1, 'user_label' => 'superadmin',
         'action' => 'plan_change', 'description' => 'Moved Volta Logistics to the Enterprise plan', 'entity_type' => 'tenant', 'entity_id' => 18,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-2 day'))],
        ['id' => 11, 'portal' => 'superadmin', 'tenant_id' => 18, 'user_id' => 1, 'user_label' => 'Support session — superadmin',
         'action' => 'impersonate_start', 'description' => 'Opened a support session for Volta Logistics', 'entity_type' => 'tenant', 'entity_id' => 18,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-6 hour'))],

        /* --- other workspaces: must never show up in tenant 18's log --- */
        ['id' => 12, 'portal' => 'admin', 'tenant_id' => 15, 'user_id' => 15, 'user_label' => 'Cocoa Coast Exports',
         'action' => 'sign_in', 'description' => 'Signed in from Takoradi, Ghana', 'entity_type' => 'tenant', 'entity_id' => 15,
         'ip_address' => '41.66.202.14', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-7 hour'))],
        ['id' => 13, 'portal' => 'admin', 'tenant_id' => 15, 'user_id' => 15, 'user_label' => 'Cocoa Coast Exports',
         'action' => 'rating_settings_update', 'description' => 'Changed the public page theme colour', 'entity_type' => 'tenant', 'entity_id' => 15,
         'ip_address' => '41.66.202.14', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-2 hour'))],
        ['id' => 14, 'portal' => 'admin', 'tenant_id' => 13, 'user_id' => 13, 'user_label' => 'Tamale Solar Ltd',
         'action' => 'service_create', 'description' => 'Added “Solar Water Pump Installation” to the services catalogue', 'entity_type' => 'service', 'entity_id' => 9,
         'ip_address' => '154.160.11.72', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-30 hour'))],

        /* --- platform-wide super admin events (tenant_id NULL) --- */
        ['id' => 15, 'portal' => 'superadmin', 'tenant_id' => null, 'user_id' => 1, 'user_label' => 'superadmin',
         'action' => 'settings_update', 'description' => 'Updated platform currency and support email', 'entity_type' => 'settings', 'entity_id' => null,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-3 day'))],
        ['id' => 16, 'portal' => 'superadmin', 'tenant_id' => null, 'user_id' => 1, 'user_label' => 'superadmin',
         'action' => 'backup_create', 'description' => 'Created platform backup backup_2026-09-14_020000.sql.gz (18.4 MB)', 'entity_type' => 'backup', 'entity_id' => null,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-2 day 4 hour'))],
        ['id' => 17, 'portal' => 'superadmin', 'tenant_id' => null, 'user_id' => 1, 'user_label' => 'superadmin',
         'action' => 'gateway_test', 'description' => 'Re-tested the Paystack live keys — available balance GH₵42,180.55', 'entity_type' => 'payment_gateway', 'entity_id' => 1,
         'ip_address' => '197.251.200.9', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         'created_at' => date('Y-m-d H:i:s', strtotime('-4 day'))],
    ],

    /* Workspace backups (admin/backups.php / includes/tenant_backups.php).
       Only tenant 18 has files registered, so the page proves both the
       populated and the empty state. The .gz files themselves are written
       at runtime by the "Create backup" action; the fixture rows older than
       today therefore show the "file missing" badge, which is also real:
       a restore replaces the folder and old history stays in the table. */
    'tenant_backups' => [
        ['id' => 3, 'tenant_id' => 18, 'filename' => 'workspace_18_2026-09-15_090400.json.gz', 'format' => 'json.gz',
         'size_bytes' => 421888, 'table_count' => 16, 'record_count' => 1284, 'created_by_label' => 'Volta Logistics',
         'created_at' => date('Y-m-d H:i:s', strtotime('-4 hour'))],
        ['id' => 2, 'tenant_id' => 18, 'filename' => 'workspace_18_2026-09-08_083000.json.gz', 'format' => 'json.gz',
         'size_bytes' => 409600, 'table_count' => 15, 'record_count' => 1201, 'created_by_label' => 'Volta Logistics',
         'created_at' => date('Y-m-d H:i:s', strtotime('-8 day'))],
        ['id' => 1, 'tenant_id' => 15, 'filename' => 'workspace_15_2026-09-01_101500.json.gz', 'format' => 'json.gz',
         'size_bytes' => 233472, 'table_count' => 16, 'record_count' => 742, 'created_by_label' => 'Cocoa Coast Exports',
         'created_at' => date('Y-m-d H:i:s', strtotime('-15 day'))],
    ],

    /* ============================================================
       Payments & billing
       ------------------------------------------------------------
       Fixtures for the financial centre. Paystack + the manual bank
       profile are switched on; Flutterwave is installed but off, so
       the screens show both the live and the "needs configuring"
       states. Money still awaiting confirmation is deliberately
       present (payments id 1 and 2) so the approvals queue renders.
       ============================================================ */
    'payment_gateways' => [
        ['id' => 1, 'gateway_key' => 'paystack', 'display_name' => 'Paystack', 'is_enabled' => 1, 'mode' => 'live',
         'public_key' => 'pk_live_7f3c9a21b4e6', 'secret_key' => 'sk_live_2b8d41f7c9a3e5d6', 'webhook_secret' => 'sk_live_2b8d41f7c9a3e5d6',
         'currency' => 'GHS', 'bank_name' => null, 'account_name' => null, 'account_number' => null,
         'instructions' => 'Cards, mobile money, bank transfer and USSD across Ghana, Nigeria, Kenya and South Africa.',
         'connection_status' => 'ok', 'connection_note' => 'Live keys work — available balance GH₵42,180.55 GHS.',
         'last_checked_at' => '2026-09-14 08:12:00', 'sort_order' => 1,
         'created_at' => '2026-06-02 09:00:00', 'updated_at' => '2026-09-14 08:12:00'],
        ['id' => 2, 'gateway_key' => 'flutterwave', 'display_name' => 'Flutterwave', 'is_enabled' => 0, 'mode' => 'test',
         'public_key' => 'FLWPUBK_TEST-4a1c7e', 'secret_key' => '', 'webhook_secret' => '',
         'currency' => 'GHS', 'bank_name' => null, 'account_name' => null, 'account_number' => null,
         'instructions' => 'Cards, mobile money, bank accounts and Barion wallets in 30+ African markets.',
         'connection_status' => 'unverified', 'connection_note' => 'Secret key is missing.',
         'last_checked_at' => null, 'sort_order' => 2,
         'created_at' => '2026-06-02 09:00:00', 'updated_at' => '2026-06-02 09:00:00'],
        ['id' => 3, 'gateway_key' => 'bank_transfer', 'display_name' => 'Bank transfer / Manual', 'is_enabled' => 1, 'mode' => 'live',
         'public_key' => null, 'secret_key' => null, 'webhook_secret' => null,
         'currency' => 'GHS', 'bank_name' => 'Ecobank Ghana', 'account_name' => 'Optibiz Ltd',
         'account_number' => '0041234567890',
         'instructions' => "Pay into the account below and quote your invoice number as the reference.\nTransfers are confirmed within one working day.",
         'connection_status' => 'ok', 'connection_note' => 'Bank transfer details are ready to publish to tenants.',
         'last_checked_at' => '2026-09-10 11:30:00', 'sort_order' => 3,
         'created_at' => '2026-06-02 09:00:00', 'updated_at' => '2026-09-10 11:30:00'],
    ],

    /* Invoices: open, awaiting confirmation, paid, overdue and cancelled */
    'payment_invoices' => [
        ['id' => 9, 'invoice_number' => 'INV-2026-0009', 'tenant_id' => 17, 'plan_id' => 2, 'purpose' => 'renewal',
         'subject' => 'Professional plan — 12 months', 'amount' => '959.88', 'discount' => '0.00', 'tax' => '0.00',
         'total' => '959.88', 'currency' => 'GHS', 'months' => 12, 'period_start' => null, 'period_end' => null,
         'status' => 'processing', 'gateway_key' => 'paystack', 'checkout_reference' => 'OPT-8F2A41C9-9',
         'due_date' => date('Y-m-d', strtotime('+6 day')), 'paid_at' => null, 'issued_by' => 'superadmin',
         'confirmed_by' => null, 'notes' => 'Renewal reminder sent with the August statement.',
         'created_at' => date('Y-m-d H:i:s', strtotime('-2 day')), 'updated_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ['id' => 8, 'invoice_number' => 'INV-2026-0008', 'tenant_id' => 14, 'plan_id' => 1, 'purpose' => 'renewal',
         'subject' => 'Starter plan — 12 months', 'amount' => '359.88', 'discount' => '0.00', 'tax' => '0.00',
         'total' => '359.88', 'currency' => 'GHS', 'months' => 12, 'period_start' => null, 'period_end' => null,
         'status' => 'open', 'gateway_key' => null, 'checkout_reference' => null,
         'due_date' => date('Y-m-d', strtotime('+11 day')), 'paid_at' => null, 'issued_by' => 'superadmin',
         'confirmed_by' => null, 'notes' => '', 'created_at' => date('Y-m-d H:i:s', strtotime('-4 day')),
         'updated_at' => date('Y-m-d H:i:s', strtotime('-4 day'))],
        ['id' => 7, 'invoice_number' => 'INV-2026-0007', 'tenant_id' => 9, 'plan_id' => 2, 'purpose' => 'renewal',
         'subject' => 'Professional plan — 12 months', 'amount' => '959.88', 'discount' => '0.00', 'tax' => '0.00',
         'total' => '959.88', 'currency' => 'GHS', 'months' => 12, 'period_start' => null, 'period_end' => null,
         'status' => 'overdue', 'gateway_key' => null, 'checkout_reference' => null,
         'due_date' => date('Y-m-d', strtotime('-24 day')), 'paid_at' => null, 'issued_by' => 'superadmin',
         'confirmed_by' => null, 'notes' => 'Chased twice by phone.', 'created_at' => date('Y-m-d H:i:s', strtotime('-40 day')),
         'updated_at' => date('Y-m-d H:i:s', strtotime('-40 day'))],
        ['id' => 6, 'invoice_number' => 'INV-2026-0006', 'tenant_id' => 18, 'plan_id' => 3, 'purpose' => 'upgrade',
         'subject' => 'Enterprise plan — 12 months', 'amount' => '2399.88', 'discount' => '120.00', 'tax' => '0.00',
         'total' => '2279.88', 'currency' => 'GHS', 'months' => 12, 'period_start' => '2026-08-24', 'period_end' => '2027-08-24',
         'status' => 'paid', 'gateway_key' => 'paystack', 'checkout_reference' => 'OPT-51C7E9B2-6',
         'due_date' => '2026-08-24', 'paid_at' => '2026-08-24 10:41:00', 'issued_by' => 'superadmin',
         'confirmed_by' => 'superadmin', 'notes' => '', 'created_at' => '2026-08-20 09:15:00', 'updated_at' => '2026-08-24 10:41:00'],
        ['id' => 5, 'invoice_number' => 'INV-2026-0005', 'tenant_id' => 15, 'plan_id' => 3, 'purpose' => 'renewal',
         'subject' => 'Enterprise plan — 12 months', 'amount' => '2399.88', 'discount' => '0.00', 'tax' => '0.00',
         'total' => '2399.88', 'currency' => 'GHS', 'months' => 12, 'period_start' => '2026-07-28', 'period_end' => '2027-07-28',
         'status' => 'paid', 'gateway_key' => 'bank_transfer', 'checkout_reference' => '',
         'due_date' => '2026-07-28', 'paid_at' => '2026-07-27 15:02:00', 'issued_by' => 'superadmin',
         'confirmed_by' => 'superadmin', 'notes' => 'Settled by bank transfer.', 'created_at' => '2026-07-20 12:00:00',
         'updated_at' => '2026-07-27 15:02:00'],
        ['id' => 4, 'invoice_number' => 'INV-2026-0004', 'tenant_id' => 11, 'plan_id' => 3, 'purpose' => 'renewal',
         'subject' => 'Enterprise plan — 12 months', 'amount' => '2399.88', 'discount' => '0.00', 'tax' => '0.00',
         'total' => '2399.88', 'currency' => 'GHS', 'months' => 12, 'period_start' => '2026-06-04', 'period_end' => '2027-06-04',
         'status' => 'paid', 'gateway_key' => 'paystack', 'checkout_reference' => 'OPT-77AB10D3-4',
         'due_date' => '2026-06-04', 'paid_at' => '2026-06-03 09:18:00', 'issued_by' => 'superadmin',
         'confirmed_by' => 'superadmin', 'notes' => '', 'created_at' => '2026-05-28 10:00:00', 'updated_at' => '2026-06-03 09:18:00'],
        ['id' => 3, 'invoice_number' => 'INV-2026-0003', 'tenant_id' => 5, 'plan_id' => 1, 'purpose' => 'renewal',
         'subject' => 'Starter plan — 12 months', 'amount' => '359.88', 'discount' => '0.00', 'tax' => '0.00',
         'total' => '359.88', 'currency' => 'GHS', 'months' => 12, 'period_start' => null, 'period_end' => null,
         'status' => 'cancelled', 'gateway_key' => null, 'checkout_reference' => null,
         'due_date' => '2026-02-21', 'paid_at' => null, 'issued_by' => 'superadmin', 'confirmed_by' => null,
         'notes' => 'Workspace cancelled before the term started.', 'created_at' => '2026-02-14 09:00:00',
         'updated_at' => '2026-02-19 16:30:00'],
    ],

    /* The money ledger. status 'pending' rows are gateway money waiting
       for the platform owner; 'confirmed' rows are revenue. */
    'subscription_payments' => [
        ['id' => 1, 'tenant_id' => 17, 'invoice_id' => 9, 'receipt_number' => 'RCP-2026-0001', 'amount' => '959.88',
         'currency' => 'GHS', 'fee' => '18.24', 'payment_method' => 'Paystack', 'gateway_key' => 'paystack',
         'gateway_reference' => 'OPT-8F2A41C9-9', 'transaction_ref' => 'OPT-8F2A41C9-9', 'channel' => 'mobile_money',
         'status' => 'pending', 'source' => 'gateway', 'payer_name' => 'Akosua Mensah', 'payer_email' => 'accounts@harmattanfoods.gh',
         'payer_phone' => '+233 20 555 0117', 'months_extended' => 12, 'notes' => 'Captured via Paystack.',
         'reject_reason' => null, 'recorded_by' => null, 'verified_by' => null, 'verified_at' => null,
         'paid_at' => date('Y-m-d H:i:s', strtotime('-1 day')), 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ['id' => 2, 'tenant_id' => 14, 'invoice_id' => 0, 'receipt_number' => 'RCP-2026-0002', 'amount' => '359.88',
         'currency' => 'GHS', 'fee' => '0.00', 'payment_method' => 'Bank transfer', 'gateway_key' => 'bank_transfer',
         'gateway_reference' => 'GCB-88231', 'transaction_ref' => 'GCB-88231', 'channel' => null,
         'status' => 'pending', 'source' => 'offline', 'payer_name' => 'Accra Dental Group', 'payer_email' => 'hello@accradental.com',
         'payer_phone' => '+233 27 555 0114', 'months_extended' => 12, 'notes' => 'Tenant declared a payment (ref GCB-88231).',
         'reject_reason' => null, 'recorded_by' => 'Tenant: Accra Dental Group', 'verified_by' => null, 'verified_at' => null,
         'paid_at' => null, 'created_at' => date('Y-m-d H:i:s', strtotime('-3 day'))],
        ['id' => 3, 'tenant_id' => 18, 'invoice_id' => 6, 'receipt_number' => 'RCP-2026-0003', 'amount' => '2279.88',
         'currency' => 'GHS', 'fee' => '43.32', 'payment_method' => 'Paystack', 'gateway_key' => 'paystack',
         'gateway_reference' => 'OPT-51C7E9B2-6', 'transaction_ref' => 'OPT-51C7E9B2-6', 'channel' => 'card',
         'status' => 'confirmed', 'source' => 'gateway', 'payer_name' => 'Kwesi Boateng', 'payer_email' => 'billing@voltalogistics.com',
         'payer_phone' => '+233 24 555 0118', 'months_extended' => 12, 'notes' => 'Captured via Paystack.',
         'reject_reason' => null, 'recorded_by' => null, 'verified_by' => 'superadmin', 'verified_at' => '2026-08-24 10:41:00',
         'paid_at' => '2026-08-24 10:40:00', 'created_at' => '2026-08-24 10:40:00'],
        ['id' => 4, 'tenant_id' => 15, 'invoice_id' => 5, 'receipt_number' => 'RCP-2026-0004', 'amount' => '2399.88',
         'currency' => 'GHS', 'fee' => '0.00', 'payment_method' => 'Bank transfer', 'gateway_key' => 'bank_transfer',
         'gateway_reference' => 'ECO-4471', 'transaction_ref' => 'ECO-4471', 'channel' => null,
         'status' => 'confirmed', 'source' => 'offline', 'payer_name' => 'Cocoa Coast Exports', 'payer_email' => 'finance@cocoacoast.gh',
         'payer_phone' => '+233 24 555 0115', 'months_extended' => 12, 'notes' => 'Settled against INV-2026-0005',
         'reject_reason' => null, 'recorded_by' => 'superadmin', 'verified_by' => 'superadmin', 'verified_at' => '2026-07-27 15:02:00',
         'paid_at' => '2026-07-27 15:00:00', 'created_at' => '2026-07-27 15:02:00'],
        ['id' => 5, 'tenant_id' => 11, 'invoice_id' => 4, 'receipt_number' => 'RCP-2026-0005', 'amount' => '2399.88',
         'currency' => 'GHS', 'fee' => '45.60', 'payment_method' => 'Paystack', 'gateway_key' => 'paystack',
         'gateway_reference' => 'OPT-77AB10D3-4', 'transaction_ref' => 'OPT-77AB10D3-4', 'channel' => 'card',
         'status' => 'confirmed', 'source' => 'gateway', 'payer_name' => 'Efua Sarpong', 'payer_email' => 'team@coastfintech.com',
         'payer_phone' => '+233 24 555 0111', 'months_extended' => 12, 'notes' => 'Captured via Paystack.',
         'reject_reason' => null, 'recorded_by' => null, 'verified_by' => 'superadmin', 'verified_at' => '2026-06-03 09:18:00',
         'paid_at' => '2026-06-03 09:17:00', 'created_at' => '2026-06-03 09:18:00'],
        ['id' => 6, 'tenant_id' => 17, 'invoice_id' => 0, 'receipt_number' => 'RCP-2026-0006', 'amount' => '79.99',
         'currency' => 'GHS', 'fee' => '0.00', 'payment_method' => 'Mobile money', 'gateway_key' => null,
         'gateway_reference' => 'MOMO-99231', 'transaction_ref' => 'MOMO-99231', 'channel' => 'mobile_money',
         'status' => 'confirmed', 'source' => 'offline', 'payer_name' => 'Harmattan Foods', 'payer_email' => 'accounts@harmattanfoods.gh',
         'payer_phone' => '+233 20 555 0117', 'months_extended' => 1, 'notes' => 'Top-up for extra companies.',
         'reject_reason' => null, 'recorded_by' => 'superadmin', 'verified_by' => 'superadmin', 'verified_at' => '2026-05-16 11:20:00',
         'paid_at' => '2026-05-16 11:18:00', 'created_at' => '2026-05-16 11:20:00'],
        ['id' => 7, 'tenant_id' => 9, 'invoice_id' => 7, 'receipt_number' => 'RCP-2026-0007', 'amount' => '200.00',
         'currency' => 'GHS', 'fee' => '4.10', 'payment_method' => 'Paystack', 'gateway_key' => 'paystack',
         'gateway_reference' => 'OPT-0CC31D77-7', 'transaction_ref' => 'OPT-0CC31D77-7', 'channel' => 'card',
         'status' => 'failed', 'source' => 'gateway', 'payer_name' => 'Yaw Owusu', 'payer_email' => 'accounts@temasteel.com',
         'payer_phone' => '+233 30 555 0109', 'months_extended' => 12, 'notes' => '',
         'reject_reason' => 'Partial amount — the workspace was asked to pay the full balance.',
         'recorded_by' => null, 'verified_by' => 'superadmin', 'verified_at' => '2026-08-19 14:02:00',
         'paid_at' => null, 'created_at' => '2026-08-19 14:01:00'],
        ['id' => 8, 'tenant_id' => 4, 'invoice_id' => 0, 'receipt_number' => 'RCP-2026-0008', 'amount' => '479.94',
         'currency' => 'GHS', 'fee' => '0.00', 'payment_method' => 'Bank transfer', 'gateway_key' => 'bank_transfer',
         'gateway_reference' => 'GCB-71004', 'transaction_ref' => 'GCB-71004', 'channel' => null,
         'status' => 'confirmed', 'source' => 'offline', 'payer_name' => 'Ada Beach Resorts', 'payer_email' => 'stay@adabeach.gh',
         'payer_phone' => '+233 20 555 0104', 'months_extended' => 6, 'notes' => '',
         'reject_reason' => null, 'recorded_by' => 'superadmin', 'verified_by' => 'superadmin', 'verified_at' => '2026-03-02 10:12:00',
         'paid_at' => '2026-03-02 10:10:00', 'created_at' => '2026-03-02 10:12:00'],
        ['id' => 9, 'tenant_id' => 7, 'invoice_id' => 0, 'receipt_number' => 'RCP-2026-0009', 'amount' => '1199.94',
         'currency' => 'GHS', 'fee' => '22.80', 'payment_method' => 'Paystack', 'gateway_key' => 'paystack',
         'gateway_reference' => 'OPT-9B4410EA-9', 'transaction_ref' => 'OPT-9B4410EA-9', 'channel' => 'bank_transfer',
         'status' => 'confirmed', 'source' => 'gateway', 'payer_name' => 'Nana Adjei', 'payer_email' => 'ops@takoradimarine.com',
         'payer_phone' => '+233 24 555 0107', 'months_extended' => 6, 'notes' => '',
         'reject_reason' => null, 'recorded_by' => null, 'verified_by' => 'superadmin', 'verified_at' => '2026-04-06 08:44:00',
         'paid_at' => '2026-04-06 08:42:00', 'created_at' => '2026-04-06 08:44:00'],
        ['id' => 10, 'tenant_id' => 3, 'invoice_id' => 0, 'receipt_number' => 'RCP-2026-0010', 'amount' => '359.88',
         'currency' => 'GHS', 'fee' => '0.00', 'payment_method' => 'Cash', 'gateway_key' => null,
         'gateway_reference' => '', 'transaction_ref' => 'CASH-2211', 'channel' => 'cash',
         'status' => 'refunded', 'source' => 'offline', 'payer_name' => 'Wa Shea Butter Co.', 'payer_email' => 'hello@washea.gh',
         'payer_phone' => '+233 27 555 0103', 'months_extended' => 12, 'notes' => 'Refunded after a duplicated charge.',
         'reject_reason' => null, 'recorded_by' => 'superadmin', 'verified_by' => 'superadmin', 'verified_at' => '2026-02-02 09:30:00',
         'paid_at' => '2026-01-14 09:00:00', 'created_at' => '2026-01-14 09:05:00'],
    ],

    'payment_refunds' => [
        ['id' => 1, 'tenant_id' => 3, 'payment_id' => 10, 'invoice_id' => 0, 'amount' => '359.88', 'currency' => 'GHS',
         'kind' => 'refund', 'reason' => 'Duplicate charge reversed at the bank.', 'status' => 'processed',
         'gateway_key' => null, 'gateway_reference' => 'CASH-2211', 'requested_by' => 'superadmin',
         'processed_by' => 'superadmin', 'processed_at' => '2026-02-02 09:30:00', 'created_at' => '2026-02-02 09:30:00'],
        ['id' => 2, 'tenant_id' => 12, 'payment_id' => 0, 'invoice_id' => 0, 'amount' => '120.00', 'currency' => 'GHS',
         'kind' => 'credit', 'reason' => 'Goodwill credit after two days of downtime.', 'status' => 'processed',
         'gateway_key' => null, 'gateway_reference' => '', 'requested_by' => 'superadmin',
         'processed_by' => 'superadmin', 'processed_at' => '2026-07-02 13:20:00', 'created_at' => '2026-07-02 13:20:00'],
    ],

    'payment_events' => [
        ['id' => 7, 'gateway_key' => 'paystack', 'event_type' => 'webhook.charge.success', 'reference' => 'OPT-8F2A41C9-9',
         'invoice_id' => 9, 'payment_id' => 1, 'signature_valid' => 1, 'ip_address' => '52.31.139.75',
         'payload' => '{"reference":"OPT-8F2A41C9-9","event":"charge.success"}', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ['id' => 6, 'gateway_key' => 'paystack', 'event_type' => 'callback.paid', 'reference' => 'OPT-8F2A41C9-9',
         'invoice_id' => 9, 'payment_id' => 1, 'signature_valid' => 1, 'ip_address' => '197.251.200.9',
         'payload' => 'Payment captured and awaiting confirmation.', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ['id' => 5, 'gateway_key' => 'bank_transfer', 'event_type' => 'payment.declared', 'reference' => 'GCB-88231',
         'invoice_id' => 8, 'payment_id' => 2, 'signature_valid' => 1, 'ip_address' => '154.160.11.72',
         'payload' => 'Workspace reported a manual payment.', 'created_at' => date('Y-m-d H:i:s', strtotime('-3 day'))],
        ['id' => 4, 'gateway_key' => 'paystack', 'event_type' => 'gateway.test', 'reference' => '',
         'invoice_id' => 0, 'payment_id' => 0, 'signature_valid' => 1, 'ip_address' => '197.251.200.9',
         'payload' => 'Live keys work — available balance GH₵42,180.55 GHS.', 'created_at' => '2026-09-14 08:12:00'],
        ['id' => 3, 'gateway_key' => 'paystack', 'event_type' => 'payment.rejected', 'reference' => 'OPT-0CC31D77-7',
         'invoice_id' => 7, 'payment_id' => 7, 'signature_valid' => 1, 'ip_address' => '197.251.200.9',
         'payload' => 'Rejected by superadmin — Partial amount.', 'created_at' => '2026-08-19 14:02:00'],
        ['id' => 2, 'gateway_key' => 'flutterwave', 'event_type' => 'webhook.bad_signature', 'reference' => '',
         'invoice_id' => 0, 'payment_id' => 0, 'signature_valid' => 0, 'ip_address' => '45.155.205.233',
         'payload' => '{"event":"charge.completed"}', 'created_at' => '2026-08-02 03:41:00'],
        ['id' => 1, 'gateway_key' => 'paystack', 'event_type' => 'payment.confirmed', 'reference' => 'OPT-77AB10D3-4',
         'invoice_id' => 4, 'payment_id' => 5, 'signature_valid' => 1, 'ip_address' => '197.251.200.9',
         'payload' => 'Confirmed by superadmin', 'created_at' => '2026-06-03 09:18:00'],
    ],

    'star_distribution' => [5 => 412, 4 => 168, 3 => 54, 2 => 17, 1 => 9],
];
