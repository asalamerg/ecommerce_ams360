export function formatMoney(
    amount: string | number,
    currency: string,
    locale: string,
): string {
    const value = Number(amount);

    try {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
        }).format(value);
    } catch {
        return `${value.toFixed(2)} ${currency}`;
    }
}
