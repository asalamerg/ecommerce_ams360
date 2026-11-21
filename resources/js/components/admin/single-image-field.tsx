import { ImagePlus, Trash2 } from 'lucide-react';
import type { ChangeEvent, DragEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { FieldError } from '@/components/admin/admin-ui';
import { Spinner } from '@/components/ui/spinner';
import { useAdminText } from '@/hooks/use-admin-text';
import { compressImage } from '@/lib/compress-image';
import { cn } from '@/lib/utils';

/**
 * One image with preview: choose or drop a file, replace it, or remove it.
 * The file is shrunk in the browser first; the server re-encodes it anyway.
 *
 * `currentUrl` is the saved image. `file` is a new choice not yet saved, and
 * `removed` asks the server to drop the saved one.
 */
export default function SingleImageField({
    label,
    currentUrl,
    file,
    removed,
    onFile,
    onRemove,
    error,
    maxSide,
    wide = false,
}: {
    label: string;
    currentUrl: string | null;
    file: File | null;
    removed: boolean;
    onFile: (file: File | null) => void;
    onRemove: () => void;
    error?: string;
    maxSide?: number;
    wide?: boolean;
}) {
    const t = useAdminText();
    const input = useRef<HTMLInputElement>(null);
    const [working, setWorking] = useState(false);
    const [dropping, setDropping] = useState(false);
    const [preview, setPreview] = useState<string | null>(null);

    useEffect(() => {
        if (!file) {
            setPreview(null);

            return;
        }

        const url = URL.createObjectURL(file);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [file]);

    const shown = preview ?? (removed ? null : currentUrl);

    async function take(chosen: File | undefined) {
        if (!chosen || !chosen.type.startsWith('image/')) {
            return;
        }

        setWorking(true);
        onFile(await compressImage(chosen, maxSide));
        setWorking(false);
    }

    function choose(event: ChangeEvent<HTMLInputElement>) {
        void take(event.target.files?.[0]);
        event.target.value = '';
    }

    function drop(event: DragEvent<HTMLButtonElement>) {
        event.preventDefault();
        setDropping(false);
        void take(event.dataTransfer.files[0]);
    }

    return (
        <div
            className={cn(
                'grid content-start gap-2 text-sm font-semibold',
                wide && 'sm:col-span-2',
            )}
        >
            <span>{label}</span>
            <div className="flex flex-wrap items-center gap-4">
                <button
                    type="button"
                    onClick={() => input.current?.click()}
                    onDragOver={(event) => {
                        event.preventDefault();
                        setDropping(true);
                    }}
                    onDragLeave={() => setDropping(false)}
                    onDrop={drop}
                    disabled={working}
                    aria-label={shown ? t('Replace image') : t('Choose image')}
                    className={cn(
                        'relative grid shrink-0 place-items-center overflow-hidden rounded-xl border-2 border-dashed bg-surface transition hover:border-brand',
                        wide ? 'aspect-[16/7] w-full max-w-md' : 'size-32',
                        dropping ? 'border-brand bg-brand/10' : 'border-line',
                    )}
                >
                    {working ? (
                        <Spinner />
                    ) : shown ? (
                        <img
                            src={shown}
                            alt=""
                            className="absolute inset-0 h-full w-full object-contain p-2"
                        />
                    ) : (
                        <span className="px-2 text-center text-xs text-ink-soft">
                            <ImagePlus
                                size={26}
                                aria-hidden="true"
                                className="mx-auto mb-1.5"
                            />
                            {t('Choose image')}
                        </span>
                    )}
                </button>

                <div className="flex flex-col gap-2">
                    {shown && (
                        <button
                            type="button"
                            onClick={() => input.current?.click()}
                            className="text-start text-sm font-bold text-brand-text hover:underline"
                        >
                            {t('Replace image')}
                        </button>
                    )}
                    {shown && (
                        <button
                            type="button"
                            onClick={() => {
                                onFile(null);
                                onRemove();
                            }}
                            className="inline-flex items-center gap-1.5 text-sm font-bold text-rose-700 hover:underline"
                        >
                            <Trash2 size={15} aria-hidden="true" />
                            {t('Remove image')}
                        </button>
                    )}
                    <span className="text-xs font-normal text-ink-soft">
                        {t('JPG, PNG or WebP, up to 5 MB.')}
                    </span>
                </div>
            </div>

            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={choose}
                className="sr-only"
                tabIndex={-1}
            />
            <FieldError message={error} />
        </div>
    );
}
