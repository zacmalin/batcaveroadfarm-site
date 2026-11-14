<?php
// includes/admin_nav.php — the Farm OS menu map, shared by the sidebar (admin/views/layout/sidebar.php) and global search
// (includes/search.php), so a page added to the menu is findable by search with no extra step (Nov 14, 2026).
// Moved out of the sidebar unchanged. A hidden item is not an authorization boundary — every page still checks access itself.
//
// Item: label, icon, short (bottom-bar label), tabs = [[tab label, href, check_type, check_value], ...],
//       match = extra views that belong to this item (highlight only).
// check_type: 'view' | 'table' | 'always' | 'pinrole'. The item links to its first tab the user can open.

if (!function_exists('bcrf_admin_nav_sections')) {

function bcrf_admin_nav_sections(): array {
    return [
    'storefront' => ['label'=>'Storefront','icon'=>'🏪','items'=>[
        ['label'=>'POS','icon'=>'💳','tabs'=>[['POS','/admin/register.php','view','pos']]],   // Farm OS slice 9: the new register (old one at /admin/pos.php?legacy=1)
        ['label'=>'Products & Inventory','short'=>'Inventory','icon'=>'📦','tabs'=>[
            ['Products',   '/admin/products.php',                     'table', 'inventory'],          // Farm OS slice 10: product editor
            ['Stock',      '/admin/stock.php',                        'view',  'inventory_update'],   // Farm OS slice 8: by location, counts, history
            ['Vendors',    '/admin/vendors.php',                      'table', 'inventory'],          // Farm OS Phase 2 slice 2.1
            ['Receive',    '/admin/receive.php',                      'table', 'inventory'],          // Farm OS Phase 2 slice 2.2
            ['Thrift',     '/admin/thrift.php',                       'table', 'inventory'],          // Farm OS Phase 2 slice 2.3
            ['Settlements','/admin/settlements.php',                  'table', 'inventory'],          // Farm OS Phase 2 consignor payouts
            ['Orders',     '/admin/purchase_orders.php',              'table', 'inventory'],          // Farm OS Phase 2 slice 2.4 PO-lite
            ['Invoices',   '/admin/documents.php',                    'table', 'inventory'],          // invoices & receipts (Nov 14, 2026)
            ['Counts',     '?view=inventory_update',                  'view',  'inventory_update'],
            ['Full editor','?view=schema&table=inventory',            'table', 'inventory'],          // C-10: administrator fallback
            ['Categories', '?view=schema&table=inventory_categories', 'table', 'inventory_categories'],
        ]],
        ['label'=>'Sales & Drawers','short'=>'Sales','icon'=>'🧾','tabs'=>[['Receipts','?view=receipts','view','receipts'],
                                                                         ['P2P matching','/admin/reconcile.php','view','p2p_reconcile'],      // Farm OS slice 6
                                                                         ['Gift Cards','/admin/gift_cards.php','view','pos']]],              // Farm OS Phase 2 gift cards (Nov 11)
    ]],
    'visitors' => ['label'=>'Visitors & Events','icon'=>'🎟','items'=>[
        ['label'=>'Bookings',       'icon'=>'📋','tabs'=>[['Bookings','?view=bookings','view','bookings'],
                                                         ['Quotes & payments','/admin/quotes.php','view','bookings'],          // Farm OS Phase 3 slice 4 (Nov 12)
                                                         ['Weather day','/admin/weather.php','view','bookings']]],             // slice 5 (Nov 12)
        ['label'=>'Private Events', 'short'=>'Parties','icon'=>'🎉','tabs'=>[['Private Events','?view=event_inquiries','view','event_inquiries'],
                                                                         ['Party quotes','/admin/quotes.php?kind=party','view','event_inquiries'],
                                                                         ['Party packages','/admin/party_packages.php','view','event_inquiries']]],   // Nov 12
        ['label'=>'Annual Passes',  'short'=>'Passes','icon'=>'🎫','tabs'=>[['Annual Passes','?view=passes','view','passes']]],
        ['label'=>'Roost Rewards',  'short'=>'Rewards','icon'=>'🐓','tabs'=>[['Roost Rewards','/admin/rewards.php','view','pos']]],   // lean rewards, Nov 2026
    ]],
    'animals' => ['label'=>'Animals','icon'=>'🐾','items'=>[
        ['label'=>'Roster',    'icon'=>'🐐','tabs'=>[['Roster','/admin/animals.php','table','animals'],                       // Farm OS Phase 5 slice 1 (Nov 12)
                                                     ['Enclosures','/admin/animals.php?tab=enclosures','table','animals'],
                                                     ['Public pages','/admin/animal_public.php','table','animals'],                 // Farm OS Phase 5 slice 9 (Nov 12)
                                                     ['Full editor','?view=schema&table=animals','table','animals']]],
        ['label'=>'Care',      'icon'=>'🥕','tabs'=>[['Log care','/admin/animal_care.php','table','animals'],                   // Farm OS Phase 5 slice 2 (Nov 12)
                                                     ['Recent','/admin/animal_care.php?tab=log','table','animals'],
                                                     ['Setup','/admin/animal_care.php?tab=setup','table','animals']]],
        ['label'=>'Health',    'icon'=>'🩺','tabs'=>[['Health','/admin/animal_health.php','view','animal_health']]],                  // Farm OS Phase 5 slice 3 (Nov 12)
        ['label'=>'Weights',   'icon'=>'⚖️','tabs'=>[['Weigh','/admin/animal_weights.php','table','animals'],                     // Farm OS Phase 5 slice 4 (Nov 12)
                                                     ['Setup','/admin/animal_weights.php?tab=setup','table','animals']]],
        ['label'=>'Breeding',  'icon'=>'🐣','tabs'=>[['Breeding','/admin/animal_breeding.php','table','animals']]],                       // Farm OS Phase 5 slice 6 (Nov 12)
        ['label'=>'Expenses',  'icon'=>'🧾','tabs'=>[['Expenses','/admin/animal_expenses.php','view','animal_expenses']]],              // Farm OS Phase 5 slice 7 (Nov 12)
        ['label'=>'Incidents', 'icon'=>'🚑','tabs'=>[['Incidents','/admin/incidents.php','view','incidents'],                         // Farm OS Phase 5 slice 8 (Nov 12)
                                                     ['Report','/admin/tasks.php?incident=new','always','']]],                   // any staff member (Staff can only open tasks.php)
        ['label'=>'Reference', 'short'=>'Ref','icon'=>'📚','tabs'=>[
            ['Breeds',          '?view=schema&table=breeds',          'table', 'breeds'],
            ['Classifications', '?view=schema&table=classifications', 'table', 'classifications'],
        ]],
    ]],
    'kitchen' => ['label'=>'Kitchen','icon'=>'🍳','items'=>[
        ['label'=>'Recipe Bible','icon'=>'📕','tabs'=>[['Recipe Bible','/admin/kitchen_bible.php','table','recipes']]],               // house recipes, view only (Nov 13)
        ['label'=>'Recipes',     'icon'=>'📖','tabs'=>[['Recipes','?view=schema&table=recipes','table','recipes'],
                                                       ['Label check','/admin/kitchen_recipes.php','table','recipes']]],                  // Farm OS Phase 4 slice 1 (Nov 12)
        ['label'=>'Ingredients', 'icon'=>'🌿','tabs'=>[['Ingredients','?view=schema&table=ingredients','table','ingredients'],
                                                       ['Label details','/admin/kitchen_ingredients.php','table','ingredients'],          // Farm OS Phase 4 slice 1 (Nov 12)
                                                       ['USDA check','/admin/kitchen_usda_check.php','table','ingredients']]],             // bulk USDA check (Nov 13)
        ['label'=>'Products',    'icon'=>'🫙','tabs'=>[['Products','/admin/kitchen_products.php','table','recipes']]],                   // Farm OS Phase 4 slice 2 (Nov 12)
        ['label'=>'Labels',      'icon'=>'🏷️','tabs'=>[['Labels','/admin/kitchen_labels.php','table','recipes'],['Printing','/admin/kitchen_label_print.php','table','recipes']]], // Farm OS Phase 4 slices 4-5 (Nov 13)
        ['label'=>'Batches',     'icon'=>'🧺','tabs'=>[['Batches','/admin/kitchen_batches.php','table','recipes'],['Trace a lot','/admin/kitchen_trace.php','table','recipes']]], // Farm OS Phase 4 slices 3, 7 (Nov 13)
        ['label'=>'Costing',     'icon'=>'💲','tabs'=>[['Costing','/admin/kitchen_costing.php','table','recipes']]],                   // Farm OS Phase 4 slice 6 (Nov 13)
        ['label'=>'Reference',   'short'=>'Ref','icon'=>'📚','tabs'=>[
            ['Cuisines',          '?view=schema&table=cuisines',          'table', 'cuisines'],
            ['Categories',        '?view=schema&table=categories',        'table', 'categories'],
            ['Measurement Units', '?view=schema&table=measurement_units', 'table', 'measurement_units'],
            ['Unit Conversions',  '?view=schema&table=unit_conversions',  'table', 'unit_conversions'],
        ]],
    ]],
    // Customers: one record across bookings, parties, passes and tickets; households; organizations (Farm OS Phase 3, Nov 12)
    'customers' => ['label'=>'Customers','icon'=>'👥','items'=>[
        ['label'=>'Customers', 'icon'=>'👥','tabs'=>[['Find','/admin/customers.php','view','customers'],
                                                      ['Possible duplicates','/admin/customers.php?dupes=1','view','customers'],
                                                      ['Organizations','/admin/organizations.php','view','customers'],
                                                      ['Waivers','/admin/waivers.php','view','customers']]],   // Phase 3 waivers (Nov 12)
    ]],
    'content' => ['label'=>'Marketing & Content','icon'=>'📡','items'=>[
        ['label'=>'Blog',            'icon'=>'✍️','tabs'=>[['Blog','?view=schema&table=blog_posts','table','blog_posts']]],
        ['label'=>'Public Events',   'short'=>'Events','icon'=>'📅','tabs'=>[['Public Events','?view=events','view','events']]],
        ['label'=>'Newsletter',      'icon'=>'📧','tabs'=>[['Newsletter','?view=newsletter','view','newsletter']]],
        ['label'=>'Social Queue',    'short'=>'Social','icon'=>'📣','tabs'=>[['Social Queue','?view=social','view','social'], ['Pinterest','?view=social&tab=pinterest','view','social']]],
        ['label'=>'Comments',        'icon'=>'💬','tabs'=>[['Comments','?view=comment_moderation','view','comment_moderation']]],
        ['label'=>'Subscribers',     'short'=>'Subs','icon'=>'💌','tabs'=>[['Subscribers','?view=schema&table=mailing_list','table','mailing_list']]],
        ['label'=>'Media Library',   'short'=>'Media','icon'=>'🖼️','tabs'=>[['Media Library','?view=media','view','media']]],
        ['label'=>'Seasonal Themes', 'short'=>'Themes','icon'=>'🎨','tabs'=>[['Seasonal Themes','?view=theme_scheduler','view','theme_scheduler']]],
    ]],
    'team' => ['label'=>'Team','icon'=>'🧑‍🌾','items'=>[
        ['label'=>'Calendar',   'icon'=>'📅','tabs'=>[['Calendar',  '/admin/calendar.php',            'view', 'calendar']]],      // master calendar + phone feed (Nov 12)
        ['label'=>'Tasks',      'icon'=>'✅','tabs'=>[['My tasks',  '/admin/tasks.php?tab=my',        'view', 'tasks'],          // Farm OS Phase 3 (Nov 11)
                                                       ['Everyone',  '/admin/tasks.php?tab=all',       'view', 'tasks_manage'],
                                                       ['Chores',    '/admin/tasks.php?tab=chores',    'view', 'tasks_manage'],
                                                       ['Checklists','/admin/tasks.php?tab=templates', 'view', 'tasks_manage'],
                                                       ['Events',    '/admin/tasks.php?tab=events',    'view', 'tasks_manage']]],   // party/booking checklists (slice 3)
        ['label'=>'Staff',      'icon'=>'🪪','tabs'=>[['Staff','?view=jobs_staff','view','jobs_staff']], 'match'=>['jobs_alerts','jobs_records']],
        ['label'=>'Time Clock', 'short'=>'Clock','icon'=>'⏰','tabs'=>[['Time Clock','?view=timecard','view','timecard']]],
        ['label'=>'Timesheets', 'icon'=>'🗓️','tabs'=>[['Timesheets','?view=timesheet','view','timesheet']]],
        ['label'=>'Hiring',     'icon'=>'📥','tabs'=>[
            ['Applicants',    '?view=jobs',            'view', 'jobs'],
            ['Emails',        '?view=jobs_emails',     'view', 'jobs_emails'],
            ['Jobs',          '?view=jobs_posts',      'view', 'jobs_posts'],
            ['Question Bank', '?view=jobs_questions',  'view', 'jobs_questions'],
            ['Categories',    '?view=jobs_categories', 'view', 'jobs_categories'],
        ], 'match'=>['jobs_hire']],
    ]],
    'reports' => ['label'=>'Reports','icon'=>'📈','items'=>[
        ['label'=>'Sales',   'icon'=>'💵','tabs'=>[['Sales','?view=sales_analytics','view','sales_analytics'],
                                                   ['Daily & tax','/admin/farm_reports.php','view','sales_analytics']]],   // Farm OS reports (Phase 1, slice 5)
        ['label'=>'Listings & Analytics', 'short'=>'Listings', 'icon'=>'📊','tabs'=>[['Listings & Analytics','?view=search_analytics','view','search_analytics']]],   // listings hub + GA4/GSC (Nov 11 2026)
        ['label'=>'Social Media', 'short'=>'Social', 'icon'=>'📣','tabs'=>[['Social Media','/admin/social_reports.php','view','social']]],   // social analytics (Nov 11 2026)
    ]],
    'settings' => ['label'=>'Settings','icon'=>'⚙️','items'=>[
        ['label'=>'Hours & Contacts', 'short'=>'Hours','icon'=>'🏠','tabs'=>[['Hours & Contacts','?view=site_info','view','site_info']]],
        ['label'=>'Payments & Register', 'short'=>'Payments','icon'=>'💳','tabs'=>[   // administrators only (moved off the register, Nov 6 2026)
            ['Payment methods', '/admin/payment_settings.php?tab=methods',  'view', 'payment_settings'],
            ['Register',        '/admin/payment_settings.php?tab=register', 'view', 'payment_settings'],
            ['Tax rates',       '/admin/payment_settings.php?tab=tax',      'view', 'payment_settings'],
        ]],
        ['label'=>'Users & Roles',    'short'=>'Users','icon'=>'🔐','tabs'=>[
            ['Staff',       '?view=schema&table=users',     'table', 'users'],       // staff accounts (Farm OS Phase 0, C-02)
            ['Customers',   '?view=schema&table=customers', 'table', 'customers'],   // public site accounts
            ['By role',     '?view=permissions',            'view',  'permissions'],   // Permissions
            ['By person',   '?view=permissions&tab=person', 'view',  'permissions'],   // extras for one staff member (Nov 14, 2026)
            ['Two-step',    '/admin/two_step.php',          'view',  'two_step'],
            ['PIN sign-in', '/admin/pin_settings.php',      'pinrole', ''],      // PINs + shared devices (Nov 13, 2026)
        ]],
        ['label'=>'Integrations',     'icon'=>'🤖','tabs'=>[['AI Connections','/admin/mcp_connections.php','view','mcp_connections'],     // administrators only
                                                              ['Google Business Profile','/admin/gbp_connect.php','view','mcp_connections'],   // Nov 11, 2026: were reachable
                                                              ['Pinterest','/admin/pinterest_connect.php','view','mcp_connections']]],       // only from Social Queue
        ['label'=>'Backups & Export', 'short'=>'Backups','icon'=>'💾','tabs'=>[['Backups','/admin/backups.php','view','backups']]],          // administrators only
        ['label'=>'System Health',    'short'=>'Health','icon'=>'🩺','tabs'=>[['System Health','/admin/system_health.php','view','system_health']]], // administrators only
        ['label'=>'Database',         'short'=>'DB','icon'=>'🗄️','tabs'=>[['Database','/admin/migrations.php','view','migrations']]],             // administrators only (Phase 0 migrations)
        ['label'=>'Audit Log',        'short'=>'Audit','icon'=>'📜','tabs'=>[['Audit Log','?view=audit_log','view','audit_log']]],
    ]],
];
}

/**
 * Pages that are not tabs in the menu but should still come up in search: [label, href, any-of checks, where].
 * Checks use the search format: ['view', x] / ['table', x] / ['always'].
 */
function bcrf_admin_nav_extra_pages(): array {
    return [
        ['Dashboard',      '/admin/admin.php?view=dashboard', [['always']],                    'Home'],
        ['Gate check-in',  '/admin/gate.php',                 [['view', 'pos'], ['view', 'passes']], 'Scan tickets and passes'],
        ['Notifications',  '/admin/notifications.php',        [['always']],                    'Alerts bell'],
    ];
}

/**
 * Extra words that find a page, by its link (the same href as in the menu). Generic farm words only; no business names,
 * so the list works for any farm that runs Farm OS. Page, item and section names are always searched as well.
 */
function bcrf_admin_nav_keywords(): array {
    return [
        '/admin/admin.php?view=dashboard'           => 'home today overview start',
        '/admin/gate.php'                           => 'check in checkin scan admission entry front gate',
        '/admin/notifications.php'                  => 'bell alerts messages',
        '/admin/register.php'                       => 'register checkout point of sale till cashier ring up',
        '/admin/products.php'                       => 'items catalog prices sku',
        '/admin/stock.php'                          => 'inventory on hand quantity levels',
        '/admin/receive.php'                        => 'delivery shipment restock',
        '/admin/thrift.php'                         => 'consignment used secondhand',
        '/admin/settlements.php'                    => 'consignor payout consignment',
        '/admin/purchase_orders.php'                => 'po purchase order supplier reorder',
        '/admin/documents.php'                      => 'invoice invoices receipt receipts bill bills paperwork scan upload expense expenses packing slip',
        '?view=inventory_update'                    => 'stock count inventory count cycle count',
        '?view=receipts'                            => 'sales transactions refunds drawer cash out',
        '/admin/reconcile.php'                      => 'venmo zelle cash app paypal peer to peer p2p',
        '/admin/gift_cards.php'                     => 'gift certificate voucher',
        '?view=bookings'                            => 'reservations school field trip groups tours',
        '/admin/quotes.php'                         => 'invoice deposit balance payment link',
        '/admin/weather.php'                        => 'rain storm snow closed closing forecast cancel',
        '?view=event_inquiries'                     => 'birthday party parties private event rental inquiry',
        '/admin/party_packages.php'                 => 'party package pricing',
        '?view=passes'                              => 'membership season pass members',
        '/admin/rewards.php'                        => 'loyalty points punch card rewards',
        '/admin/animals.php'                        => 'livestock goats sheep chickens herd',
        '/admin/animal_care.php'                    => 'feeding feed chores water bedding',
        '/admin/animal_health.php'                  => 'vet vaccine vaccination medication medicine sick treatment',
        '/admin/animal_weights.php'                 => 'weight scale weigh',
        '/admin/animal_breeding.php'                => 'breeding kidding lambing births pregnancy',
        '/admin/incidents.php'                      => 'injury accident bite escape report',
        '/admin/kitchen_labels.php'                 => 'nutrition facts allergens label',
        '/admin/kitchen_batches.php'                => 'production batch lot made',
        '/admin/kitchen_trace.php'                  => 'recall lot trace',
        '/admin/kitchen_costing.php'                => 'cost margin food cost',
        '/admin/customers.php'                      => 'crm guests people contacts visitors',
        '/admin/waivers.php'                        => 'release liability signed waiver',
        '?view=schema&table=blog_posts'             => 'articles posts writing',
        '?view=newsletter'                          => 'email campaign mailing',
        '?view=social'                              => 'facebook instagram google business post',
        '?view=comment_moderation'                  => 'reviews moderation',
        '?view=schema&table=mailing_list'           => 'email list mailing list',
        '?view=media'                               => 'images photos pictures uploads',
        '?view=theme_scheduler'                     => 'holiday seasonal theme',
        '/admin/calendar.php'                       => 'schedule ical feed agenda',
        '/admin/tasks.php?tab=my'                   => 'todo to do jobs work',
        '/admin/tasks.php?tab=chores'               => 'recurring daily chores',
        '?view=jobs_staff'                          => 'employees team people workers',
        '?view=timecard'                            => 'clock in clock out punch',
        '?view=timesheet'                           => 'payroll hours worked',
        '?view=jobs'                                => 'applications applicants hiring candidates',
        '?view=jobs_posts'                          => 'job posting openings',
        '?view=sales_analytics'                     => 'revenue reports numbers',
        '/admin/farm_reports.php'                   => 'daily report sales tax end of day',
        '?view=search_analytics'                    => 'google analytics search console seo listings',
        '?view=site_info'                           => 'hours phone address contact open closed business info',
        '/admin/payment_settings.php?tab=methods'   => 'stripe card cash payment methods',
        '/admin/payment_settings.php?tab=register'  => 'receipt printer register settings',
        '/admin/payment_settings.php?tab=tax'       => 'sales tax rates',
        '?view=schema&table=users'                  => 'accounts logins employees',
        '?view=permissions'                         => 'roles access rights',
        '?view=permissions&tab=person'              => 'extra access one person employee',
        '/admin/two_step.php'                       => '2fa mfa two factor authenticator security',
        '/admin/pin_settings.php'                   => 'pin tablet kiosk shared device',
        '/admin/mcp_connections.php'                => 'ai assistant mcp api connector',
        '/admin/gbp_connect.php'                    => 'google maps listing gbp',
        '/admin/backups.php'                        => 'export download backup csv',
        '/admin/system_health.php'                  => 'errors logs cron status',
        '/admin/migrations.php'                     => 'database migrations upgrade schema update',
        '?view=audit_log'                           => 'history changes who changed log',
    ];
}

}
