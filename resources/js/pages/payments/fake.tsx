import { Head, router } from '@inertiajs/react';

/**
 * The test payment provider's page (development and tests only). It stands
 * where a real provider's hosted card form would be.
 */
export default function FakePaymentPage({
    reference,
    amount,
    currency,
    decideUrl,
}: {
    reference: string;
    amount: string;
    currency: string;
    decideUrl: string;
}) {
    const decide = (decision: 'paid' | 'failed') =>
        router.post(decideUrl, { decision });

    return (
        <div className="grid min-h-screen place-items-center bg-slate-100 p-4 text-slate-900">
            <Head title="Test payment" />
            <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-lg">
                <p className="rounded bg-amber-100 px-2 py-1 text-center text-xs font-bold text-amber-900">
                    TEST GATEWAY · no real money
                </p>
                <p className="mt-5 text-center text-sm text-slate-500">
                    Amount to pay
                </p>
                <p className="text-center text-3xl font-black" dir="ltr">
                    {Number(amount).toFixed(2)} {currency}
                </p>
                <p
                    className="mt-1 text-center font-mono text-xs text-slate-400"
                    dir="ltr"
                >
                    {reference}
                </p>
                <div className="mt-6 grid gap-2">
                    <button
                        type="button"
                        onClick={() => decide('paid')}
                        className="h-11 rounded-lg bg-emerald-600 font-bold text-white hover:bg-emerald-700"
                    >
                        Approve payment
                    </button>
                    <button
                        type="button"
                        onClick={() => decide('failed')}
                        className="h-11 rounded-lg border border-slate-300 font-bold hover:bg-slate-50"
                    >
                        Decline
                    </button>
                </div>
            </div>
        </div>
    );
}
