export type ProductColor = { id: number; name: string; hex: string };

export type ProductCard = {
    id: number;
    name: string;
    slug: string;
    /** What the customer pays, after any running discount. */
    price: string;
    /** The price before the discount; null when there is none. */
    original_price: string | null;
    discount_percent: number;
    discount_ends_at: string | null;
    currency: string;
    stock_quantity: number;
    price_is_estimate: boolean;
    availability_unconfirmed: boolean;
    purchasable: boolean;
    max_quantity: number;
    image_url: string | null;
    second_image_url: string | null;
    category: string | null;
    brand: string | null;
    brand_slug?: string | null;
    colors: ProductColor[];
    is_new: boolean;
};

export type OfferCard = {
    id: number;
    title: string;
    description: string | null;
    image_url: string | null;
    ends_at: string | null;
    discount_type: 'percentage' | 'fixed';
    discount_value: string;
};

export type CategoryCard = {
    name: string;
    slug: string;
    image_url: string | null;
    count: number;
};

export type CartLine = ProductCard & {
    line_key: string;
    color: ProductColor | null;
    quantity: number;
    available: boolean;
    line_total: string;
};

export type CartSummary = {
    count: number;
    subtotal: string;
    coupon: string | null;
    discount: string;
    total: string;
    currency: string;
    has_estimate: boolean;
    has_unconfirmed: boolean;
    items: CartLine[];
};

export type FooterData = {
    phone: string | null;
    phone_link: string | null;
    whatsapp: string | null;
    has_sim_plans: boolean;
    facebook: string | null;
    instagram: string | null;
    address: string | null;
    pages: { slug: string; title: string }[];
    categories: { slug: string; name: string }[];
};
