import { Link, usePage } from "@inertiajs/react";
import {
    Facebook,
    Instagram,
    MapPin,
    MessageCircle,
    Phone,
} from "lucide-react";
import type { ReactNode } from "react";
import { useTranslations } from "@/hooks/use-translations";
import StoreLogo from "@/components/store/store-logo";

const link = "inline-block py-1 opacity-75 transition hover:opacity-100";
const social =
    "grid h-9 w-9 place-items-center rounded-full bg-on-footer/10 transition hover:bg-brand hover:text-on-brand";

function Column({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <h2 className="mb-2 text-sm font-black">{title}</h2>
            <ul className="text-sm">{children}</ul>
        </div>
    );
}

/**
 * Everything here comes from the admin panel (StorefrontFooter): contact
 * details and social links from Settings, links from Pages, and the busiest
 * categories. Six columns on a wide screen, two on a phone.
 */
export default function StoreFooter() {
    const { auth, footer, locale, store } = usePage().props;
    const t = useTranslations();

    const whatsappLink = footer?.whatsapp
        ? `${footer.whatsapp}?text=${encodeURIComponent(t("store.whatsapp_hello"))}`
        : null;

    return (
        <footer className="mt-16 bg-footer text-on-footer">
            <div className="mx-auto grid max-w-7xl grid-cols-2 gap-x-6 gap-y-8 px-4 py-10 sm:grid-cols-3 sm:px-6 lg:grid-cols-6 lg:gap-8 lg:px-8">
                <div className="col-span-2 sm:col-span-3 lg:col-span-1">
                    <Link
                        href={`/${locale}`}
                        className="text-2xl font-black tracking-tight"
                    >
                        <StoreLogo imageClassName="h-10" />
                    </Link>
                    <p className="mt-3 max-w-xs text-sm leading-6 opacity-75">
                        {t("store.footer_about")}
                    </p>
                    {(footer?.facebook || footer?.instagram) && (
                        <div className="mt-4 flex gap-2">
                            {footer.facebook && (
                                <a
                                    href={footer.facebook}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label="Facebook"
                                    className={social}
                                >
                                    <Facebook size={16} aria-hidden="true" />
                                </a>
                            )}
                            {footer.instagram && (
                                <a
                                    href={footer.instagram}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label="Instagram"
                                    className={social}
                                >
                                    <Instagram size={16} aria-hidden="true" />
                                </a>
                            )}
                        </div>
                    )}
                </div>

                <Column title={t("store.footer_shop")}>
                    <li>
                        <Link href={`/${locale}/products`} className={link}>
                            {t("nav.all_products")}
                        </Link>
                    </li>
                    {footer?.categories.map((category) => (
                        <li key={category.slug}>
                            <Link
                                href={`/${locale}/categories/${category.slug}`}
                                className={link}
                            >
                                {category.name}
                            </Link>
                        </li>
                    ))}
                    <li>
                        <Link href={`/${locale}/categories`} className={link}>
                            {t("store.all_categories")}
                        </Link>
                    </li>
                </Column>

                <Column title={t("service.menu")}>
                    <li>
                        <Link href={`/${locale}/contact`} className={link}>
                            {t("service.contact_title")}
                        </Link>
                    </li>
                    <li>
                        <Link href={`/${locale}/repairs`} className={link}>
                            {t("service.repairs_title")}
                        </Link>
                    </li>
                    {footer?.has_sim_plans && (
                        <li>
                            <Link
                                href={`/${locale}/sim-plans`}
                                className={link}
                            >
                                {t("service.sim_title")}
                            </Link>
                        </li>
                    )}
                    <li>
                        <Link href={`/${locale}/track`} className={link}>
                            {t("order_updates.track_link")}
                        </Link>
                    </li>
                    {footer?.pages.map((page) => (
                        <li key={page.slug}>
                            <Link
                                href={`/${locale}/pages/${page.slug}`}
                                className={link}
                            >
                                {page.title}
                            </Link>
                        </li>
                    ))}
                </Column>

                <Column title={t("store.footer_account")}>
                    <li>
                        <Link href={`/${locale}/cart`} className={link}>
                            {t("store.cart")}
                        </Link>
                    </li>
                    {auth.user ? (
                        <li>
                            <Link href={`/${locale}/account`} className={link}>
                                {t("nav.account")}
                            </Link>
                        </li>
                    ) : (
                        <>
                            <li>
                                <Link href="/login" className={link}>
                                    {t("nav.login")}
                                </Link>
                            </li>
                            <li>
                                <Link href="/register" className={link}>
                                    {t("nav.register")}
                                </Link>
                            </li>
                        </>
                    )}
                    <li>
                        <Link href="/admin/login" className={link}>
                            {t("nav.staff_login")}
                        </Link>
                    </li>
                </Column>

                {(footer?.phone || whatsappLink || footer?.address) && (
                    <div className="col-span-2 sm:col-span-1">
                        <h2 className="mb-2 text-sm font-black">
                            {t("store.contact_us")}
                        </h2>
                        <ul className="grid gap-2 text-sm">
                            {footer?.phone && (
                                <li>
                                    <a
                                        href={`tel:${footer.phone_link ?? footer.phone}`}
                                        aria-label={`${t("store.call_us")} ${footer.phone}`}
                                        className="inline-flex items-center gap-2 font-bold opacity-90 transition hover:opacity-100"
                                    >
                                        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-on-footer/10">
                                            <Phone
                                                size={16}
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <span dir="ltr">{footer.phone}</span>
                                    </a>
                                </li>
                            )}
                            {whatsappLink && (
                                <li>
                                    <a
                                        href={whatsappLink}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-2 rounded-full bg-[#25D366] px-3.5 py-2 font-bold whitespace-nowrap text-[#0b3d1f] shadow-sm transition hover:bg-[#1ebe5b]"
                                    >
                                        <MessageCircle
                                            size={17}
                                            aria-hidden="true"
                                        />
                                        {t("store.whatsapp_chat")}
                                    </a>
                                </li>
                            )}
                            {footer?.address && (
                                <li className="flex items-start gap-2 opacity-75">
                                    <MapPin
                                        size={15}
                                        aria-hidden="true"
                                        className="mt-1 shrink-0"
                                    />
                                    {footer.address}
                                </li>
                            )}
                        </ul>
                    </div>
                )}

                <div className="col-span-2 sm:col-span-1">
                    <h2 className="mb-2 text-sm font-black">
                        {t("store.developed_by")}
                    </h2>
                    <a
                        href="https://wa.me/972599107446"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1.5 rounded-full border border-on-footer/25 px-3 py-1.5 text-sm font-bold transition hover:bg-brand hover:text-on-brand"
                    >
                        <MessageCircle size={14} aria-hidden="true" />
                        {t("store.dev_company")}
                    </a>
                    <p className="mt-2 text-xs leading-5 opacity-60">
                        {t("store.dev_tagline")}
                    </p>
                </div>
            </div>
            <div className="border-t border-on-footer/10">
                <p className="mx-auto max-w-7xl px-4 py-4 text-center text-xs opacity-60 sm:px-6 lg:px-8">
                    © {new Date().getFullYear()} {store.name} —{" "}
                    {t("store.rights")}
                </p>
            </div>
        </footer>
    );
}
