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

return [
    'super_admins' => [
        ['id' => 1, 'username' => 'superadmin', 'email' => 'superadmin@optibiz.com', 'password' => $sa_password_hash, 'created_at' => '2026-01-01 09:00:00'],
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
        ['id' => 51, 'tenant_id' => 18, 'company_name' => 'Volta Haulage Division', 'category_name' => 'Manufacturing', 'email' => 'ops@voltahaulage.gh', 'phone' => '+233 24 555 0301', 'website' => 'volta-haulage.gh', 'created_at' => '2026-08-25 10:00:00', 'rating_count' => 64, 'avg_rating' => 4.8],
        ['id' => 52, 'tenant_id' => 18, 'company_name' => 'Volta Cold Storage', 'category_name' => 'Retail', 'email' => 'ops@voltacold.gh', 'phone' => '+233 24 555 0302', 'website' => 'voltacold.gh', 'created_at' => '2026-08-26 10:00:00', 'rating_count' => 41, 'avg_rating' => 4.6],
        ['id' => 53, 'tenant_id' => 18, 'company_name' => 'Volta Freight Forwarding', 'category_name' => 'Technology', 'email' => 'ops@voltafreight.com', 'phone' => '+233 24 555 0303', 'website' => 'voltafreight.com', 'created_at' => '2026-08-27 10:00:00', 'rating_count' => 38, 'avg_rating' => 4.7],
        ['id' => 54, 'tenant_id' => 18, 'company_name' => 'Volta Warehouse Tema', 'category_name' => 'Manufacturing', 'email' => 'ops@voltawh.gh', 'phone' => '', 'website' => '', 'created_at' => '2026-08-28 10:00:00', 'rating_count' => 29, 'avg_rating' => 4.4],
        ['id' => 55, 'tenant_id' => 18, 'company_name' => 'Volta Last-Mile Accra', 'category_name' => 'Retail', 'email' => 'ops@voltalastmile.gh', 'phone' => '', 'website' => 'voltalastmile.gh', 'created_at' => '2026-08-29 10:00:00', 'rating_count' => 24, 'avg_rating' => 4.5],
        ['id' => 41, 'tenant_id' => 15, 'company_name' => 'Cocoa Coast Exports', 'category_name' => 'Manufacturing', 'email' => 'info@cocoacoast.gh', 'phone' => '', 'website' => 'cocoacoast.gh', 'created_at' => '2026-07-29 10:00:00', 'rating_count' => 71, 'avg_rating' => 4.9],
        ['id' => 31, 'tenant_id' => 7, 'company_name' => 'Takoradi Marine Terminal', 'category_name' => 'Manufacturing', 'email' => 'info@takoradimarine.com', 'phone' => '', 'website' => 'takoradimarine.com', 'created_at' => '2026-03-28 10:00:00', 'rating_count' => 128, 'avg_rating' => 4.8],
        ['id' => 32, 'tenant_id' => 11, 'company_name' => 'Cape Coast Fintech Hub', 'category_name' => 'Finance', 'email' => 'info@coastfintech.com', 'phone' => '', 'website' => 'coastfintech.com', 'created_at' => '2026-06-05 10:00:00', 'rating_count' => 96, 'avg_rating' => 4.7],
        ['id' => 33, 'tenant_id' => 10, 'company_name' => 'Ashanti AgriCo Depot', 'category_name' => 'Retail', 'email' => 'info@ashantiagri.gh', 'phone' => '', 'website' => '', 'created_at' => '2026-05-19 10:00:00', 'rating_count' => 58, 'avg_rating' => 4.3],
        ['id' => 34, 'tenant_id' => 13, 'company_name' => 'Tamale Solar Installers', 'category_name' => 'Technology', 'email' => 'info@tamalesolar.com', 'phone' => '', 'website' => 'tamalesolar.com', 'created_at' => '2026-07-03 10:00:00', 'rating_count' => 41, 'avg_rating' => 4.5],
    ],

    'ratings_recent' => [
        ['id' => 901, 'rating' => 5, 'customer_name' => 'Abena Owusu', 'customer_email' => 'abena@example.com', 'comment' => 'Drivers were on time and the cargo tracking page is excellent.', 'company_name' => 'Volta Haulage Division', 'company_id' => 51, 'created_at' => '2026-09-02 06:40:00'],
        ['id' => 900, 'rating' => 4, 'customer_name' => 'Kojo Antwi', 'customer_email' => 'kojo@example.com', 'comment' => 'Good service, but the invoice arrived two days late.', 'company_name' => 'Volta Cold Storage', 'company_id' => 52, 'created_at' => '2026-09-02 00:15:00'],
        ['id' => 899, 'rating' => 5, 'customer_name' => 'Nii Armah', 'customer_email' => 'nii@example.com', 'comment' => 'Smooth customs clearance, will use them again.', 'company_name' => 'Volta Freight Forwarding', 'company_id' => 53, 'created_at' => '2026-09-01 12:20:00'],
        ['id' => 898, 'rating' => 3, 'customer_name' => 'Grace Mensah', 'customer_email' => 'grace@example.com', 'comment' => 'Pallets were mislabelled on arrival.', 'company_name' => 'Volta Warehouse Tema', 'company_id' => 54, 'created_at' => '2026-08-31 09:05:00'],
        ['id' => 897, 'rating' => 5, 'customer_name' => 'Yaw Danso', 'customer_email' => 'yaw@example.com', 'comment' => 'Best in the western region.', 'company_name' => 'Takoradi Marine Terminal', 'company_id' => 31, 'created_at' => '2026-08-30 16:44:00'],
    ],

    // ratings collected per day (index 0 = 62 days ago … last = today)
    'ratings_per_day' => [2, 4, 1, 6, 3, 0, 0, 5, 2, 7, 4, 1, 3, 0, 1, 6, 8, 2, 4, 5, 0, 0, 3, 7, 9, 2, 1, 4, 0, 2,
                         5, 6, 3, 8, 1, 0, 0, 4, 7, 5, 2, 9, 3, 1, 6, 0, 0, 2, 8, 4, 7, 3, 5, 1, 0, 2, 9, 6, 4, 8,
                         3, 5, 11],

    'star_distribution' => [5 => 412, 4 => 168, 3 => 54, 2 => 17, 1 => 9],
];
