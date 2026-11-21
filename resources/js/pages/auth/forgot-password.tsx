import { Form, Head, setLayoutProps } from '@inertiajs/react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    const t = useTranslations();
    const title = t('auth.forgot_title');

    setLayoutProps({ title, description: t('auth.forgot_description') });

    return (
        <>
            <Head title={title} />

            {status && (
                <div
                    role="status"
                    className="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"
                >
                    {status}
                </div>
            )}

            <div className="space-y-6">
                <Form {...email.form()}>
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">{t('auth.email')}</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    dir="ltr"
                                    autoComplete="email"
                                    autoFocus
                                    placeholder={t('auth.email_placeholder')}
                                />
                                <InputError message={errors.email} />
                            </div>

                            <Button
                                className="mt-6 h-11 w-full bg-brand font-bold text-on-brand transition hover:bg-brand-hover"
                                disabled={processing}
                                data-test="email-password-reset-link-button"
                            >
                                {processing && <Spinner />}
                                {t('auth.send_reset_link')}
                            </Button>
                        </>
                    )}
                </Form>

                <div className="text-center text-sm text-muted-foreground">
                    {t('auth.return_to')}{' '}
                    <TextLink href={login()}>{t('auth.sign_in_link')}</TextLink>
                </div>
            </div>
        </>
    );
}
