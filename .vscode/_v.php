<?php
$c = new mysqli('localhost', 'root', '', 'company_rating_saas');
$hash = password_hash('UiTest123!', PASSWORD_DEFAULT);
$stmt = $c->prepare("INSERT INTO super_admins (username, password, email, is_owner, permissions) VALUES ('__ui_view', ?, 'v@t.local', 1, NULL)");
$stmt->bind_param('s', $hash);
$stmt->execute();
echo 'ok id=' . $stmt->insert_id . PHP_EOL;
