import { Head, Link, router, useForm, usePage } from "@inertiajs/react";
import {
    CreditCard,
    MessageCircle,
    ShoppingCart,
    Store,
    Tag,
    Trash2,
    Truck,
    X,
} from "lucide-react";
import type { FormEvent } from "react";
import { useState } from "react";
import { FieldError } from "@/components/admin/admin-ui";
import { LineColor } from "@/components/store/color-dots";
import PointsBox from "@/components/store/points-box";
import QuantityStepper from "@/components/store/quantity-stepper";
import Turnstile from "@/components/store/turnstile";
import { Spinner } from "@/components/ui/spinner";
import { removeCartLine, updateCartLine } from "@/hooks/use-cart";
import { useTranslations } from "@/hooks/use-translations";
import StorefrontLayout from "@/layouts/storefront-layout";
import type { LoyaltyInfo } from "@/lib/loyalty";
import { pointsPreview } from "@/lib/loyalty";
import { formatMoney } from "@/lib/store";
import FreeDeliveryProgress from "@/components/store/free-delivery-progress";

type Zone = {
    id: number;
    name: string;
    fee: string;
    free_over: string | null;
    estimate: string | null;
};

type Props = {
    customer: {
        name: string;
        phone: string | null;
        address: string | null;
        shipping_zone_id: number | null;
    } | null;
    turnstileSiteKey: string | null;
    loyalty: LoyaltyInfo | null;
    delivery: {
        zones: Zone[];
        branches: {
            id: number;
            name: string;
            address: string | null;
            phone: string | null;
        }[];
    };
    onlinePayment: boolean;
};

export default function StorefrontCart({
    customer,
    turnstileSiteKey,
    loyalty,
    delivery,
    onlinePayment,
}: Props) {
    const { cart, errors, locale } = usePage().props as ReturnType<
        typeof usePage
    >["props"] & { errors: Record<string, string> };
    const t = useTranslations();
    const [couponCode, setCouponCode] = useState("");
    const [turnstileKey, setTurnstileKey] = useState(0);
    const items = cart?.items ?? [];
    const estimate = cart?.has_estimate ? "≈ " : "";
    const money = (amount: string | number) =>
        formatMoney(amount, cart?.currency ?? "ILS", "en");

    const form = useForm({
        customer_name: customer?.name ?? "",
        phone: customer?.phone ?? "",
        email: "",
        address: customer?.address ?? "",
        notes: "",
        delivery_method: "delivery" as "delivery" | "pickup",
        shipping_zone_id: delivery.zones.some(
            (zone) => zone.id === customer?.shipping_zone_id,
        )
            ? String(customer?.shipping_zone_id)
            : "",
        branch_id: "",
        payment_method: (onlinePayment ? "online" : "whatsapp") as
            "online" | "whatsapp",
        accept_estimate: false,
        website: "",
        turnstile_token: "",
        use_points: "",
    });
    const payable = Number(cart?.total ?? 0);
    const points = loyalty
        ? pointsPreview(loyalty, payable, Number(form.data.use_points))
        : null;
    // A preview only: the server works the fee out again from the zone.
    const pickup = form.data.delivery_method === "pickup";
    const zone = pickup
        ? undefined
        : delivery.zones.find(
              (option) => String(option.id) === form.data.shipping_zone_id,
          );
    const shippingFee =
        zone && !(zone.free_over && payable >= Number(zone.free_over))
            ? Number(zone.fee)
            : 0;
    const online = form.data.payment_method === "online";
    // The chosen zone's threshold; before one is chosen, the threshold every
    // zone shares, if they all have the same one.
    const sharedFreeOver = [
        ...new Set(delivery.zones.map((option) => option.free_over)),
    ];
    const freeOver = pickup
        ? null
        : zone
          ? zone.free_over
          : sharedFreeOver.length === 1
            ? sharedFreeOver[0]
            : null;

    function applyCoupon() {
        if (couponCode.trim()) {
            router.post(
                "/cart/coupon",
                { code: couponCode },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ["cart", "errors"],
                    onSuccess: () => setCouponCode(""),
                },
            );
        }
    }

    function removeCoupon() {
        router.delete("/cart/coupon", {
            preserveScroll: true,
            preserveState: true,
            only: ["cart", "errors"],
        });
    }

    function placeOrder(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        // Opened during the click, because browsers block a tab opened after a
        // request. The confirmation page keeps a button in case this one is blocked.
        // An online payment goes to the provider's page instead.
        const whatsapp = online ? null : window.open("about:blank", "_blank");

        form.post("/checkout", {
            preserveScroll: true,
            onSuccess: (page) => {
                const { order } = page.props as unknown as {
                    order: { whatsapp_url: string | null };
                };

                if (whatsapp && order.whatsapp_url) {
                    whatsapp.opener = null;
                    whatsapp.location.href = order.whatsapp_url;
                } else {
                    whatsapp?.close();
                }
            },
            onError: () => {
                whatsapp?.close();

                // A Turnstile token is good for one submission only.
                if (turnstileSiteKey) {
                    form.setData("turnstile_token", "");
                    setTurnstileKey((key) => key + 1);
                }
            },
        });
    }

    const pageError = errors.cart;

    return (
        <StorefrontLayout>
            <Head title={t("store.cart")} />
            <main className="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10">
                <h1 className="mb-6 text-2xl font-black sm:text-3xl">
                    {t("store.cart")}
                </h1>

                {pageError && (
                    <p
                        role="alert"
                        className="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800"
                    >
                        {pageError}
                    </p>
                )}

                {items.length === 0 ? (
                    <div className="rounded-panel border border-line bg-panel px-6 py-16 text-center">
                        <ShoppingCart
                            size={40}
                            aria-hidden="true"
                            className="mx-auto mb-3 text-ink-soft"
                        />
                        <h2 className="text-lg font-black">
                            {t("store.cart_empty")}
                        </h2>
                        <p className="mt-1 text-sm text-ink-soft">
                            {t("store.cart_empty_hint")}
                        </p>
                        <Link
                            href={`/${locale}/products`}
                            className="mt-5 inline-block rounded-btn bg-brand px-6 py-3 font-bold text-on-brand transition hover:bg-brand-hover"
                        >
                            {t("store.shop_now")}
                        </Link>
                    </div>
                ) : (
                    <form
                        onSubmit={placeOrder}
                        className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]"
                    >
                        <div className="min-w-0 space-y-5">
                            <ul className="divide-y divide-line rounded-panel border border-line bg-panel">
                                {items.map((item) => (
                                    <li
                                        key={item.line_key}
                                        className="flex gap-3 p-3 sm:gap-4 sm:p-4"
                                    >
                                        <Link
                                            href={`/${locale}/products/${item.slug}`}
                                            className="h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-surface sm:h-24 sm:w-24"
                                        >
                                            {item.image_url && (
                                                <img
                                                    src={item.image_url}
                                                    alt=""
                                                    loading="lazy"
                                                    className="h-full w-full object-contain p-1"
                                                />
                                            )}
                                        </Link>
                                        <div className="min-w-0 flex-1">
                                            <Link
                                                href={`/${locale}/products/${item.slug}`}
                                                className="line-clamp-2 text-sm font-bold hover:text-brand-text sm:text-base"
                                            >
                                                {item.name}
                                            </Link>
                                            <LineColor color={item.color} />
                                            {item.price_is_estimate && (
                                                <span className="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-900">
                                                    {t("store.approximate")}
                                                </span>
                                            )}
                                            <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                                                {item.available ? (
                                                    <QuantityStepper
                                                        value={item.quantity}
                                                        max={item.max_quantity}
                                                        onChange={(quantity) =>
                                                            updateCartLine(
                                                                item.id,
                                                                quantity,
                                                                item.color?.id,
                                                            )
                                                        }
                                                    />
                                                ) : (
                                                    <span className="text-sm font-semibold text-rose-700">
                                                        {t(
                                                            "store.unavailable_line",
                                                        )}
                                                    </span>
                                                )}
                                                <span className="flex items-center gap-2">
                                                    <span
                                                        className="font-black"
                                                        dir="ltr"
                                                    >
                                                        {money(item.line_total)}
                                                    </span>
                                                    {item.original_price && (
                                                        <s
                                                            className="text-xs text-ink-soft"
                                                            dir="ltr"
                                                            aria-label={t(
                                                                "store.was_price",
                                                            )}
                                                        >
                                                            {money(
                                                                Number(
                                                                    item.original_price,
                                                                ) *
                                                                    item.quantity,
                                                            )}
                                                        </s>
                                                    )}
                                                    <button
                                                        type="button"
                                                        aria-label={t(
                                                            "store.remove",
                                                        )}
                                                        title={t(
                                                            "store.remove",
                                                        )}
                                                        onClick={() =>
                                                            removeCartLine(
                                                                item.id,
                                                                item.color?.id,
                                                            )
                                                        }
                                                        className="grid h-9 w-9 place-items-center rounded-full text-ink-soft transition hover:bg-surface hover:text-rose-700"
                                                    >
                                                        <Trash2
                                                            size={16}
                                                            aria-hidden="true"
                                                        />
                                                    </button>
                                                </span>
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>

                            <section className="rounded-panel border border-line bg-panel p-5">
                                <h2 className="font-black">
                                    {t("checkout.details")}
                                </h2>
                                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label className="grid content-start gap-1.5 text-sm font-semibold">
                                        {t("checkout.customer_name")}
                                        <input
                                            required
                                            autoComplete="name"
                                            maxLength={120}
                                            value={form.data.customer_name}
                                            onChange={(event) =>
                                                form.setData(
                                                    "customer_name",
                                                    event.target.value,
                                                )
                                            }
                                            className="admin-input"
                                        />
                                        <FieldError
                                            message={form.errors.customer_name}
                                        />
                                    </label>
                                    <label className="grid content-start gap-1.5 text-sm font-semibold">
                                        {t("checkout.customer_phone")}
                                        <input
                                            required
                                            type="tel"
                                            dir="ltr"
                                            autoComplete="tel"
                                            maxLength={25}
                                            value={form.data.phone}
                                            onChange={(event) =>
                                                form.setData(
                                                    "phone",
                                                    event.target.value,
                                                )
                                            }
                                            className="admin-input"
                                        />
                                        <FieldError
                                            message={form.errors.phone}
                                        />
                                    </label>
                                    {!customer && (
                                        <label className="grid content-start gap-1.5 text-sm font-semibold sm:col-span-2">
                                            {t("order_updates.email_label")}
                                            <input
                                                type="email"
                                                dir="ltr"
                                                autoComplete="email"
                                                maxLength={255}
                                                value={form.data.email}
                                                onChange={(event) =>
                                                    form.setData(
                                                        "email",
                                                        event.target.value,
                                                    )
                                                }
                                                className="admin-input"
                                            />
                                            <span className="text-xs font-normal text-ink-soft">
                                                {t("order_updates.email_hint")}
                                            </span>
                                            <FieldError
                                                message={form.errors.email}
                                            />
                                        </label>
                                    )}
                                    {!pickup && (
                                        <label className="grid content-start gap-1.5 text-sm font-semibold sm:col-span-2">
                                            {t("checkout.customer_address")}
                                            <input
                                                required={
                                                    delivery.zones.length > 0
                                                }
                                                autoComplete="street-address"
                                                maxLength={500}
                                                value={form.data.address}
                                                onChange={(event) =>
                                                    form.setData(
                                                        "address",
                                                        event.target.value,
                                                    )
                                                }
                                                className="admin-input"
                                            />
                                            <FieldError
                                                message={form.errors.address}
                                            />
                                        </label>
                                    )}
                                    <label className="grid content-start gap-1.5 text-sm font-semibold sm:col-span-2">
                                        {t("checkout.customer_notes")}
                                        <textarea
                                            rows={2}
                                            maxLength={1000}
                                            value={form.data.notes}
                                            onChange={(event) =>
                                                form.setData(
                                                    "notes",
                                                    event.target.value,
                                                )
                                            }
                                            className="admin-input"
                                        />
                                    </label>
                                    {/* Honeypot: hidden from people and screen readers, so only bots fill it. */}
                                    <input
                                        type="text"
                                        name="website"
                                        tabIndex={-1}
                                        autoComplete="off"
                                        aria-hidden="true"
                                        value={form.data.website}
                                        onChange={(event) =>
                                            form.setData(
                                                "website",
                                                event.target.value,
                                            )
                                        }
                                        className="absolute -start-[9999px] h-px w-px opacity-0"
                                    />
                                </div>
                            </section>

                            <section className="rounded-panel border border-line bg-panel p-5">
                                <h2 className="font-black">
                                    {t("delivery.title")}
                                </h2>
                                {delivery.branches.length > 0 && (
                                    <div
                                        role="radiogroup"
                                        className="mt-4 grid gap-2 sm:grid-cols-2"
                                    >
                                        {(["delivery", "pickup"] as const).map(
                                            (method) => (
                                                <label
                                                    key={method}
                                                    className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm font-bold transition ${form.data.delivery_method === method ? "border-brand bg-brand/5" : "border-line"}`}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="delivery_method"
                                                        checked={
                                                            form.data
                                                                .delivery_method ===
                                                            method
                                                        }
                                                        onChange={() =>
                                                            form.setData(
                                                                "delivery_method",
                                                                method,
                                                            )
                                                        }
                                                    />
                                                    {method === "delivery" ? (
                                                        <Truck
                                                            size={18}
                                                            aria-hidden="true"
                                                        />
                                                    ) : (
                                                        <Store
                                                            size={18}
                                                            aria-hidden="true"
                                                        />
                                                    )}
                                                    {t(`delivery.${method}`)}
                                                </label>
                                            ),
                                        )}
                                    </div>
                                )}

                                {pickup ? (
                                    <label className="mt-4 grid gap-1.5 text-sm font-semibold">
                                        {t("delivery.branch")}
                                        <select
                                            required
                                            value={form.data.branch_id}
                                            onChange={(event) =>
                                                form.setData(
                                                    "branch_id",
                                                    event.target.value,
                                                )
                                            }
                                            className="admin-input"
                                        >
                                            <option value="">
                                                {t("delivery.choose_branch")}
                                            </option>
                                            {delivery.branches.map((branch) => (
                                                <option
                                                    key={branch.id}
                                                    value={branch.id}
                                                >
                                                    {branch.name}
                                                    {branch.address
                                                        ? ` · ${branch.address}`
                                                        : ""}
                                                </option>
                                            ))}
                                        </select>
                                        <FieldError
                                            message={form.errors.branch_id}
                                        />
                                        <span className="text-xs font-normal text-ink-soft">
                                            {t("delivery.pickup_hint")}
                                        </span>
                                    </label>
                                ) : (
                                    delivery.zones.length > 0 && (
                                        <label className="mt-4 grid gap-1.5 text-sm font-semibold">
                                            {t("delivery.zone")}
                                            <select
                                                required
                                                value={
                                                    form.data.shipping_zone_id
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        "shipping_zone_id",
                                                        event.target.value,
                                                    )
                                                }
                                                className="admin-input"
                                            >
                                                <option value="">
                                                    {t("delivery.choose_zone")}
                                                </option>
                                                {delivery.zones.map((zone) => (
                                                    <option
                                                        key={zone.id}
                                                        value={zone.id}
                                                    >
                                                        {zone.name} ·{" "}
                                                        {Number(zone.fee) > 0
                                                            ? money(zone.fee)
                                                            : t(
                                                                  "delivery.free",
                                                              )}
                                                        {zone.estimate
                                                            ? ` · ${zone.estimate}`
                                                            : ""}
                                                    </option>
                                                ))}
                                            </select>
                                            <FieldError
                                                message={
                                                    form.errors.shipping_zone_id
                                                }
                                            />
                                            {freeOver && (
                                                <div className="mt-1 font-normal">
                                                    <FreeDeliveryProgress
                                                        total={payable}
                                                        threshold={Number(
                                                            freeOver,
                                                        )}
                                                        money={money}
                                                    />
                                                </div>
                                            )}
                                        </label>
                                    )
                                )}
                            </section>

                            {onlinePayment && (
                                <section className="rounded-panel border border-line bg-panel p-5">
                                    <h2 className="font-black">
                                        {t("payments.title")}
                                    </h2>
                                    <div
                                        role="radiogroup"
                                        className="mt-4 grid gap-2 sm:grid-cols-2"
                                    >
                                        {(["online", "whatsapp"] as const).map(
                                            (method) => (
                                                <label
                                                    key={method}
                                                    className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition ${form.data.payment_method === method ? "border-brand bg-brand/5" : "border-line"}`}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="payment_method"
                                                        className="mt-1"
                                                        checked={
                                                            form.data
                                                                .payment_method ===
                                                            method
                                                        }
                                                        onChange={() =>
                                                            form.setData(
                                                                "payment_method",
                                                                method,
                                                            )
                                                        }
                                                    />
                                                    <span>
                                                        <span className="block font-bold">
                                                            {t(
                                                                `payments.${method}`,
                                                            )}
                                                        </span>
                                                        <span className="text-xs text-ink-soft">
                                                            {t(
                                                                `payments.${method}_hint`,
                                                            )}
                                                        </span>
                                                    </span>
                                                </label>
                                            ),
                                        )}
                                    </div>
                                    <FieldError
                                        message={form.errors.payment_method}
                                    />
                                </section>
                            )}
                        </div>

                        <aside className="rounded-panel border border-line bg-panel p-5 lg:sticky lg:top-24">
                            <h2 className="font-black">
                                {t("store.order_summary")}
                            </h2>

                            {cart?.coupon ? (
                                <div className="mt-4 flex items-center justify-between rounded-lg bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800">
                                    <span className="inline-flex items-center gap-2">
                                        <Tag size={15} aria-hidden="true" />
                                        {cart.coupon}
                                    </span>
                                    <button
                                        type="button"
                                        aria-label={t("store.remove")}
                                        onClick={removeCoupon}
                                    >
                                        <X size={16} aria-hidden="true" />
                                    </button>
                                </div>
                            ) : (
                                <div className="mt-4">
                                    <div className="flex gap-2">
                                        <input
                                            value={couponCode}
                                            dir="ltr"
                                            onChange={(event) =>
                                                setCouponCode(
                                                    event.target.value,
                                                )
                                            }
                                            onKeyDown={(event) => {
                                                if (event.key === "Enter") {
                                                    event.preventDefault();
                                                    applyCoupon();
                                                }
                                            }}
                                            placeholder={t(
                                                "checkout.coupon_placeholder",
                                            )}
                                            aria-label={t(
                                                "checkout.coupon_code",
                                            )}
                                            className="admin-input min-w-0 flex-1"
                                        />
                                        <button
                                            type="button"
                                            onClick={applyCoupon}
                                            className="rounded-btn border border-line px-4 text-sm font-bold transition hover:border-brand hover:text-brand-text"
                                        >
                                            {t("checkout.apply_coupon")}
                                        </button>
                                    </div>
                                    <FieldError message={errors.coupon} />
                                </div>
                            )}

                            <dl className="mt-4 space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-ink-soft">
                                        {t("checkout.subtotal")}
                                    </dt>
                                    <dd dir="ltr">
                                        {estimate}
                                        {money(cart?.subtotal ?? 0)}
                                    </dd>
                                </div>
                                {Number(cart?.discount) > 0 && (
                                    <div className="flex justify-between text-rose-700">
                                        <dt>{t("checkout.discount")}</dt>
                                        <dd dir="ltr">
                                            -{money(cart?.discount ?? 0)}
                                        </dd>
                                    </div>
                                )}
                                {points && points.discount > 0 && (
                                    <div className="flex justify-between text-rose-700">
                                        <dt>
                                            {t("points.discount", {
                                                points: points.used,
                                            })}
                                        </dt>
                                        <dd dir="ltr">
                                            -{money(points.discount)}
                                        </dd>
                                    </div>
                                )}
                                {(zone || pickup) && (
                                    <div className="flex justify-between">
                                        <dt className="text-ink-soft">
                                            {t(
                                                pickup
                                                    ? "delivery.pickup"
                                                    : "delivery.fee",
                                            )}
                                        </dt>
                                        <dd dir="ltr">
                                            {shippingFee > 0
                                                ? money(shippingFee)
                                                : t("delivery.free")}
                                        </dd>
                                    </div>
                                )}
                                <div className="flex justify-between border-t border-line pt-2 text-lg font-black">
                                    <dt>{t("checkout.total")}</dt>
                                    <dd dir="ltr">
                                        {estimate}
                                        {money(
                                            payable -
                                                (points?.discount ?? 0) +
                                                shippingFee,
                                        )}
                                    </dd>
                                </div>
                            </dl>

                            {loyalty && (
                                <PointsBox
                                    loyalty={loyalty}
                                    payable={payable}
                                    value={form.data.use_points}
                                    onChange={(value) =>
                                        form.setData("use_points", value)
                                    }
                                    error={form.errors.use_points}
                                    money={money}
                                />
                            )}

                            {cart?.has_estimate && (
                                <div className="mt-4 rounded-lg bg-amber-50 p-3 text-xs leading-5 text-amber-950">
                                    <p className="font-bold">
                                        {t("checkout.estimate_title")}
                                    </p>
                                    <p>{t("checkout.estimate_may_change")}</p>
                                    <label className="mt-2 flex items-start gap-2 font-semibold">
                                        <input
                                            type="checkbox"
                                            required
                                            checked={form.data.accept_estimate}
                                            onChange={(event) =>
                                                form.setData(
                                                    "accept_estimate",
                                                    event.target.checked,
                                                )
                                            }
                                            className="admin-checkbox mt-0.5 shrink-0"
                                        />
                                        <span>
                                            {t("checkout.estimate_consent")}
                                        </span>
                                    </label>
                                    <FieldError
                                        message={errors.accept_estimate}
                                    />
                                </div>
                            )}

                            {turnstileSiteKey && (
                                <>
                                    <Turnstile
                                        key={turnstileKey}
                                        siteKey={turnstileSiteKey}
                                        locale={locale}
                                        onToken={(token) =>
                                            form.setData(
                                                "turnstile_token",
                                                token,
                                            )
                                        }
                                    />
                                    <FieldError
                                        message={
                                            form.errors.turnstile_token ??
                                            form.errors.website
                                        }
                                    />
                                </>
                            )}
                            {!turnstileSiteKey && (
                                <FieldError message={form.errors.website} />
                            )}

                            <button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    !cart?.count ||
                                    (!!turnstileSiteKey &&
                                        !form.data.turnstile_token)
                                }
                                className={`mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-btn font-bold text-white transition disabled:cursor-not-allowed disabled:opacity-50 ${online ? "bg-brand text-on-brand hover:bg-brand-hover" : "bg-emerald-600 hover:bg-emerald-700"}`}
                            >
                                {form.processing ? (
                                    <Spinner />
                                ) : online ? (
                                    <CreditCard size={18} aria-hidden="true" />
                                ) : (
                                    <MessageCircle
                                        size={18}
                                        aria-hidden="true"
                                    />
                                )}
                                {t(
                                    online
                                        ? "payments.pay_now"
                                        : "checkout.place_order",
                                )}
                            </button>
                            <Link
                                href={`/${locale}/products`}
                                className="mt-2 block py-2 text-center text-sm font-bold text-brand-text hover:underline"
                            >
                                {t("store.keep_shopping")}
                            </Link>
                        </aside>
                    </form>
                )}
            </main>
        </StorefrontLayout>
    );
}
