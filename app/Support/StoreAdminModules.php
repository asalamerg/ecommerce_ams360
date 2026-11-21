<?php

namespace App\Support;

class StoreAdminModules
{
    /**
     * Modules whose edit form asks for the password again: roles and points,
     * the points programme, and store settings. See ConfirmRecentPassword.
     */
    public const array SENSITIVE = ['users', 'loyalty', 'settings'];

    /**
     * The pictures a service can show on the home page; the store draws each
     * one (resources/js/pages/storefront/home.tsx).
     */
    public const array SERVICE_ICONS = [
        'shield' => 'Warranty',
        'truck' => 'Delivery',
        'wrench' => 'Repairs',
        'card' => 'Payment',
        'headset' => 'Customer support',
        'returns' => 'Returns',
        'store' => 'Store pickup',
        'award' => 'Quality',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'brands' => self::module('Brands', 'Manage storefront brands.', 'brands', 'content', [
                self::field('slug', 'Slug', 'text', 'required|string|max:120|alpha_dash', unique: true),
                ...self::localized('name', 'Name', required: true),
                ...self::localized('description', 'Description', 'textarea'),
                self::image('logo_url', 'Logo', maxSide: 600, trim: true),
                self::field('aliases', 'Other spellings for search (comma separated)', 'textarea', 'nullable|string|max:1000'),
                self::field('sort_order', 'Display order', 'number', 'required|integer|min:0|max:65535', default: 0),
                self::activeField(),
            ], ['name' => 'Brand', 'logo_url' => 'Logo', 'is_active' => 'Active']),
            'hero-slides' => self::module('Hero slides', 'Manage homepage promotional slides.', 'hero_slides', 'content', [
                self::image('image_url', 'Slide image', maxSide: 2400, required: true, wide: true),
                ...self::localized('title', 'Title', required: true),
                ...self::localized('subtitle', 'Subtitle', 'textarea'),
                self::field('link_url', 'Link URL', 'url', 'nullable|url|max:2048'),
                self::field('sort_order', 'Display order', 'number', 'required|integer|min:0|max:65535', default: 0),
                self::activeField(),
            ], ['title' => 'Slide', 'image_url' => 'Image', 'is_active' => 'Active']),
            'pages' => self::module('Pages', 'Manage multilingual storefront pages.', 'pages', 'content', [
                self::field('slug', 'Slug', 'text', 'required|string|max:150|alpha_dash', unique: true),
                ...self::localized('title', 'Title', required: true),
                ...self::localized('content', 'Content', 'textarea', required: true),
                self::activeField(),
            ], ['title' => 'Page', 'slug' => 'Slug', 'is_active' => 'Published']),
            'coupons' => self::module('Coupons', 'Manage discount codes and usage limits.', 'coupons', 'admin', [
                self::field('code', 'Coupon code', 'text', 'required|string|max:80|alpha_dash:ascii', unique: true),
                self::field('discount_type', 'Discount type', 'select', 'required|in:percentage,fixed', options: ['percentage' => 'Percentage', 'fixed' => 'Fixed amount']),
                self::field('discount_value', 'Discount value', 'number', 'required|numeric|min:0.0001|max:999999999'),
                self::field('max_uses', 'Maximum uses', 'number', 'nullable|integer|min:1|max:4294967295'),
                self::field('expires_at', 'Expires at', 'datetime-local', 'nullable|date'),
                self::activeField(),
            ], ['code' => 'Code', 'discount_type' => 'Type', 'discount_value' => 'Value', 'is_active' => 'Active'], searchable: ['code'], canCreate: true, canDelete: true),
            'offers' => self::module('Offers', 'Timed discounts on chosen products, a whole category or a whole brand. Prices change in the store as soon as an offer starts and go back when it ends.', 'offers', 'admin', [
                ...self::localized('title', 'Title', required: true),
                ...self::localized('description', 'Description', 'textarea'),
                self::image('image_url', 'Image', maxSide: 1600, wide: true),
                self::field('discount_type', 'Discount type', 'select', 'required|in:percentage,fixed', options: ['percentage' => 'Percentage', 'fixed' => 'Fixed amount']),
                self::field('discount_value', 'Discount value', 'number', 'required|numeric|min:0.0001|max:999999999'),
                self::relations('product_ids', 'Products in this offer', 'products'),
                self::relation('category_id', 'Or a whole category', 'categories'),
                self::relation('brand_id', 'Or a whole brand', 'brands'),
                self::field('starts_at', 'Starts at', 'datetime-local', 'nullable|date'),
                self::field('ends_at', 'Ends at', 'datetime-local', 'nullable|date|after_or_equal:starts_at'),
                self::activeField(),
            ], ['title' => 'Offer', 'discount_type' => 'Type', 'discount_value' => 'Value', 'is_active' => 'Active'], canCreate: true, canDelete: true),
            'sim-plans' => self::module('SIM plans', 'Manage mobile and data plans.', 'sim_plans', 'content', [
                self::field('carrier', 'Carrier', 'text', 'required|string|max:120'),
                ...self::localized('name', 'Plan name', required: true),
                self::field('data_gb', 'Data (GB)', 'number', 'nullable|numeric|min:0|max:999999'),
                self::field('minutes', 'Minutes', 'number', 'nullable|integer|min:0|max:4294967295'),
                self::field('sms', 'SMS', 'number', 'nullable|integer|min:0|max:4294967295'),
                self::field('monthly_price', 'Monthly price', 'number', 'required|numeric|min:0|max:999999999'),
                self::field('contract_months', 'Contract months', 'number', 'required|integer|min:0|max:65535', default: 0),
                self::listField('features', 'Plan features'),
                self::activeField(),
                self::field('sort_order', 'Display order', 'number', 'required|integer|min:0|max:65535', default: 0),
            ], ['name' => 'Plan', 'carrier' => 'Carrier', 'monthly_price' => 'Monthly price', 'is_active' => 'Active']),
            'services' => self::module('Services', 'Manage services shown in the storefront.', 'services', 'content', [
                ...self::localized('title', 'Title', required: true),
                ...self::localized('description', 'Description', 'textarea'),
                self::field('icon', 'Icon', 'select', 'nullable|in:'.implode(',', array_keys(self::SERVICE_ICONS)), options: self::SERVICE_ICONS),
                self::field('sort_order', 'Display order', 'number', 'required|integer|min:0|max:65535', default: 0),
                self::activeField(),
            ], ['title' => 'Service', 'sort_order' => 'Order', 'is_active' => 'Active']),
            'branches' => self::module('Branches', 'Manage store branches and opening hours.', 'branches', 'content', [
                ...self::localized('name', 'Name', required: true),
                ...self::localized('address', 'Address', 'textarea', required: true),
                self::field('phone', 'Phone', 'text', 'nullable|string|max:40'),
                self::field('lat', 'Latitude', 'number', 'nullable|numeric|between:-90,90'),
                self::field('lng', 'Longitude', 'number', 'nullable|numeric|between:-180,180'),
                self::hoursField('working_hours', 'Opening hours'),
                self::field('allows_pickup', 'Customers can collect orders here', 'checkbox', 'required|boolean', default: true),
                self::activeField(),
            ], ['name' => 'Branch', 'phone' => 'Phone', 'is_active' => 'Active']),
            'maintenance' => self::module('Maintenance', 'Track repair requests and work status.', 'maintenance_requests', 'support', [
                self::readOnlyField('device_type', 'Device'),
                self::readOnlyField('device_model', 'Model'),
                self::readOnlyField('issue', 'Reported issue', 'textarea'),
                self::readOnlyField('image_urls', 'Photos sent with the request', 'images'),
                self::readOnlyField('contact_phone', 'Contact phone'),
                self::field('status', 'Status', 'select', 'required|in:received,diagnosing,repairing,ready,delivered', options: [
                    'received' => 'Received', 'diagnosing' => 'Diagnosing', 'repairing' => 'Repairing', 'ready' => 'Ready', 'delivered' => 'Delivered',
                ]),
                self::field('estimated_cost', 'Estimated cost', 'number', 'nullable|numeric|min:0|max:999999999'),
                self::relation('branch_id', 'Branch', 'branches'),
            ], ['device_type' => 'Device', 'device_model' => 'Model', 'contact_phone' => 'Phone', 'status' => 'Status'], searchable: ['device_type', 'device_model', 'contact_phone']),
            'support' => self::module('Support tickets', 'Review support requests and update ticket status.', 'support_tickets', 'support', [
                self::readOnlyField('subject', 'Subject'),
                self::readOnlyField('message', 'Customer message', 'textarea'),
                self::field('status', 'Status', 'select', 'required|in:open,in_progress,closed', options: [
                    'open' => 'Open', 'in_progress' => 'In progress', 'closed' => 'Closed',
                ]),
            ], ['subject' => 'Subject', 'status' => 'Status'], searchable: ['subject']),
            'contact' => self::module('Contact messages', 'Review contact messages and mark them read.', 'contact_messages', 'support', [
                self::readOnlyField('name', 'Name'),
                self::readOnlyField('email', 'Email'),
                self::readOnlyField('phone', 'Phone'),
                self::readOnlyField('message', 'Message', 'textarea'),
                self::field('is_read', 'Read', 'checkbox', 'required|boolean', default: false),
            ], ['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'is_read' => 'Read'], canDelete: true, searchable: ['name', 'email', 'phone']),
            'reviews' => self::module('Reviews', 'Moderate customer product reviews.', 'reviews', 'support', [
                self::readOnlyField('product_id', 'Product ID', 'number'),
                self::readOnlyField('customer_name', 'Customer'),
                self::readOnlyField('rating', 'Rating', 'number'),
                self::readOnlyField('comment', 'Review', 'textarea'),
                self::field('is_approved', 'Approved for display', 'checkbox', 'required|boolean', default: false),
            ], ['product_id' => 'Product ID', 'customer_name' => 'Customer', 'rating' => 'Rating', 'is_approved' => 'Approved'], canDelete: true),
            'users' => self::module('Users', 'Manage customer profiles and staff roles.', 'users', 'admin', [
                self::field('name', 'Name', 'text', 'required|string|max:255'),
                self::field('email', 'Email', 'email', 'required|email|max:255', unique: true),
                self::field('phone', 'Phone', 'text', 'nullable|string|max:40'),
                self::field('preferred_locale', 'Preferred language', 'select', 'required|in:ar,en,he', options: ['ar' => 'Arabic', 'en' => 'English', 'he' => 'Hebrew']),
                self::field('role', 'Role', 'select', 'required|in:owner,administrator,product_manager,inventory_manager,order_manager,support_agent,customer', options: [
                    'owner' => 'Owner', 'administrator' => 'Administrator', 'product_manager' => 'Product manager',
                    'inventory_manager' => 'Inventory manager', 'order_manager' => 'Order manager', 'support_agent' => 'Support agent', 'customer' => 'Customer',
                ]),
                self::field('is_active', 'Account active (can sign in)', 'checkbox', 'required|boolean', default: true),
                self::readOnlyField('points_balance', 'Points balance', 'number'),
                self::virtualField('points_adjustment', 'Add or deduct points', 'number', 'nullable|integer|min:-100000000|max:100000000|not_in:0'),
                self::virtualField('points_note', 'Reason for the points change', 'text', 'nullable|string|max:255|required_with:points_adjustment'),
            ], ['name' => 'Name', 'email' => 'Email', 'role' => 'Role', 'points_balance' => 'Points', 'is_active' => 'Active'], searchable: ['name', 'email', 'phone']),
            'loyalty' => self::module('Loyalty points', 'Set how customers earn points on completed orders and spend them at checkout.', 'loyalty_settings', 'admin', [
                self::field('is_enabled', 'Points programme active', 'checkbox', 'required|boolean', default: false),
                self::field('spend_per_point', 'Amount spent to earn 1 point (₪)', 'number', 'required|numeric|min:0.01|max:1000000', default: 100),
                self::field('point_value', 'Value of 1 point (₪)', 'number', 'required|numeric|min:0.0001|max:100000', default: 1),
                self::field('min_redeem_points', 'Minimum points per order', 'number', 'required|integer|min:0|max:100000000', default: 0),
                self::field('max_redeem_percent', 'Most of an order that points can pay (%)', 'number', 'required|integer|min:1|max:100', default: 50),
            ], ['is_enabled' => 'Active', 'spend_per_point' => 'Amount spent to earn 1 point (₪)', 'point_value' => 'Value of 1 point (₪)'], singleton: true),
            'shipping-zones' => self::module('Delivery zones', 'Areas you deliver to, with their fee and when delivery is free.', 'shipping_zones', 'admin', [
                ...self::localized('name', 'Zone name', required: true),
                self::field('fee', 'Delivery fee (₪)', 'number', 'required|numeric|min:0|max:100000', default: 0),
                self::field('free_over', 'Free delivery from (₪, empty: never)', 'number', 'nullable|numeric|min:0|max:10000000'),
                ...self::localized('estimate', 'Delivery time (for example: 1-2 working days)'),
                self::field('sort_order', 'Display order', 'number', 'required|integer|min:0|max:65535', default: 0),
                self::activeField(),
            ], ['name' => 'Zone', 'fee' => 'Delivery fee (₪)', 'is_active' => 'Active'], canCreate: true, canDelete: true),
            'settings' => self::module('Store settings', 'Manage store contact details and policies.', 'site_settings', 'admin', [
                ...self::localized('site_name', 'Store name', required: true),
                ...self::localized('address', 'Address', 'textarea'),
                self::image('logo_url', 'Logo', maxSide: 600),
                self::field('phone', 'Phone', 'text', 'nullable|string|max:40'),
                self::field('whatsapp', 'WhatsApp', 'text', 'nullable|string|max:40'),
                self::field('notification_email', 'Alert e-mail for new orders and low stock', 'email', 'nullable|email|max:255'),
                self::field('facebook_url', 'Facebook URL', 'url', 'nullable|url|max:2048'),
                self::field('instagram_url', 'Instagram URL', 'url', 'nullable|url|max:2048'),
                ...self::localized('delivery_info', 'Delivery information', 'textarea'),
                ...self::localized('return_policy', 'Return policy', 'textarea'),
            ], ['site_name' => 'Store', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp'], singleton: true),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, string>  $columns
     * @param  list<string>  $searchable  Plain text columns the list page can search.
     * @return array<string, mixed>
     */
    private static function module(
        string $title,
        string $description,
        string $table,
        string $permission,
        array $fields,
        array $columns,
        bool $singleton = false,
        ?bool $canCreate = null,
        ?bool $canDelete = null,
        array $searchable = [],
    ): array {
        return [
            ...compact('title', 'description', 'table', 'permission', 'fields', 'columns', 'singleton', 'searchable'),
            'can_create' => $canCreate ?? $permission === 'content',
            'can_delete' => $canDelete ?? ($permission === 'content' && ! $singleton),
        ];
    }

    /**
     * @param  array<string, string>|null  $options
     * @return array<string, mixed>
     */
    private static function field(
        string $name,
        string $label,
        string $type,
        string $rules,
        ?array $options = null,
        mixed $default = null,
        bool $unique = false,
    ): array {
        return array_filter(compact('name', 'label', 'type', 'rules', 'options', 'default', 'unique'), fn (mixed $value): bool => $value !== null);
    }

    /**
     * An uploaded image. The column keeps its URL: /storage/uploads/... for an
     * upload, or an https:// link saved before uploads existed. The form sends
     * the new file as {name}_file and a removal as {name}_remove; the file is
     * re-encoded by App\Services\ImageUploads and shrunk to $maxSide pixels.
     *
     * @return array<string, mixed>
     */
    private static function image(string $name, string $label, int $maxSide, bool $required = false, bool $wide = false, bool $trim = false): array
    {
        return [
            // Logos: the empty margin is cut away so they show at the same size.
            'trim' => $trim,
            'name' => $name,
            'label' => $label,
            'type' => 'image',
            'rules' => ($required ? "required_without:{$name}_file|" : '').'nullable|string|max:2048',
            'required' => $required,
            'max_side' => $maxSide,
            'wide' => $wide,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function localized(
        string $column,
        string $label,
        string $type = 'text',
        bool $required = false,
    ): array {
        return array_map(
            fn (string $locale): array => [
                'name' => "{$column}_{$locale}",
                'label' => $label,
                'type' => $type,
                // Arabic is the store's main language; an empty English or Hebrew
                // text shows the Arabic one (ProductCardData::localized).
                'rules' => ($required && $locale === 'ar' ? 'required' : 'nullable').'|string|max:'.($type === 'textarea' ? 20000 : 255),
                'column' => $column,
                'locale' => $locale,
            ],
            ['ar', 'en', 'he'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function activeField(): array
    {
        return self::field('is_active', 'Active', 'checkbox', 'required|boolean', default: true);
    }

    /**
     * One row of another table, picked from a searchable list. Stored as its id.
     *
     * @return array<string, mixed>
     */
    private static function relation(string $name, string $label, string $table): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'relation', 'relation' => $table, 'rules' => "nullable|integer|exists:{$table},id"];
    }

    /**
     * Several rows of another table. Stored as a JSON array of ids, checked by
     * StoreModuleController against the table.
     *
     * @return array<string, mixed>
     */
    private static function relations(string $name, string $label, string $table): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'relations', 'relation' => $table, 'rules' => 'nullable|json|max:20000', 'wide' => true];
    }

    /**
     * Opening and closing time for each day of the week. Stored as JSON.
     *
     * @return array<string, mixed>
     */
    private static function hoursField(string $name, string $label): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'hours', 'rules' => 'nullable|json|max:5000', 'wide' => true];
    }

    /**
     * A short list of lines. Stored as a JSON array of strings.
     *
     * @return array<string, mixed>
     */
    private static function listField(string $name, string $label): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'list', 'rules' => 'nullable|json|max:10000', 'wide' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function virtualField(string $name, string $label, string $type, string $rules): array
    {
        // Validated and handled by StoreModuleController, never written as a column.
        return compact('name', 'label', 'type', 'rules') + ['virtual' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function readOnlyField(string $name, string $label, string $type = 'text'): array
    {
        return [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'rules' => 'nullable|string',
            'read_only' => true,
        ];
    }
}
