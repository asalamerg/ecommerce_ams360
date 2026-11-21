import { ImagePlus, Star, Trash2 } from 'lucide-react';
import type { ChangeEvent, DragEvent } from 'react';
import { useRef, useState } from 'react';
import { Spinner } from '@/components/ui/spinner';
import { useAdminText } from '@/hooks/use-admin-text';
import { compressImage } from '@/lib/compress-image';
import { cn } from '@/lib/utils';

export const MAX_PRODUCT_IMAGES = 8;

export type ImageItem =
    | { key: string; kind: 'existing'; id: number; url: string }
    | { key: string; kind: 'new'; file: File; url: string };

/**
 * The first image is the main one shown on product cards. Images can be dragged
 * into order, or moved to the front with the star on touch screens.
 */
export default function ProductImages({
    items,
    onChange,
    errors,
}: {
    items: ImageItem[];
    onChange: (items: ImageItem[]) => void;
    errors: string[];
}) {
    const t = useAdminText();
    const input = useRef<HTMLInputElement>(null);
    const dragged = useRef<number | null>(null);
    const [working, setWorking] = useState(false);
    const [dropping, setDropping] = useState(false);
    const room = MAX_PRODUCT_IMAGES - items.length;

    async function addFiles(files: File[]) {
        setWorking(true);

        const images = files
            .filter((file) => file.type.startsWith('image/'))
            .slice(0, room);
        const compressed = await Promise.all(
            images.map((file) => compressImage(file)),
        );

        onChange([
            ...items,
            ...compressed.map((file) => ({
                key: `${file.name}-${crypto.randomUUID()}`,
                kind: 'new' as const,
                file,
                url: URL.createObjectURL(file),
            })),
        ]);
        setWorking(false);
    }

    function choose(event: ChangeEvent<HTMLInputElement>) {
        void addFiles([...(event.target.files ?? [])]);
        event.target.value = '';
    }

    function dropFiles(event: DragEvent<HTMLButtonElement>) {
        event.preventDefault();
        setDropping(false);
        void addFiles([...event.dataTransfer.files]);
    }

    function move(from: number, to: number) {
        const next = [...items];
        const [item] = next.splice(from, 1);

        next.splice(to, 0, item);
        onChange(next);
    }

    return (
        <section className="rounded-panel border border-line bg-panel p-5 sm:p-6">
            <h2 className="text-lg font-extrabold">{t('Product images')}</h2>
            <p className="mt-1 text-sm text-ink-soft">
                {t(
                    'JPG, PNG or WebP, up to :count images. The first image is the main one shown on the store. Drag images to reorder them.',
                    { count: MAX_PRODUCT_IMAGES },
                )}
            </p>

            <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {items.map((item, index) => (
                    <figure
                        key={item.key}
                        draggable
                        onDragStart={() => {
                            dragged.current = index;
                        }}
                        onDragOver={(event) => event.preventDefault()}
                        onDrop={(event) => {
                            event.preventDefault();

                            if (
                                dragged.current !== null &&
                                dragged.current !== index
                            ) {
                                move(dragged.current, index);
                            }

                            dragged.current = null;
                        }}
                        className={cn(
                            'group relative aspect-square cursor-move overflow-hidden rounded-xl border-2 bg-surface',
                            index === 0 ? 'border-brand' : 'border-line',
                        )}
                    >
                        <img
                            src={item.url}
                            alt=""
                            className="h-full w-full object-contain p-2"
                        />
                        {index === 0 && (
                            <figcaption className="absolute start-2 top-2 rounded-full bg-brand px-2 py-0.5 text-[11px] font-bold text-on-brand">
                                {t('Main image')}
                            </figcaption>
                        )}
                        <div className="absolute end-2 bottom-2 flex gap-1">
                            {index > 0 && (
                                <button
                                    type="button"
                                    aria-label={t('Make main image')}
                                    title={t('Make main image')}
                                    onClick={() => move(index, 0)}
                                    className="grid h-8 w-8 place-items-center rounded-full bg-panel text-ink shadow transition hover:text-brand-text"
                                >
                                    <Star size={15} aria-hidden="true" />
                                </button>
                            )}
                            <button
                                type="button"
                                aria-label={t('Remove image')}
                                title={t('Remove image')}
                                onClick={() =>
                                    onChange(
                                        items.filter(
                                            (other) => other.key !== item.key,
                                        ),
                                    )
                                }
                                className="grid h-8 w-8 place-items-center rounded-full bg-panel text-rose-700 shadow transition hover:bg-rose-50"
                            >
                                <Trash2 size={15} aria-hidden="true" />
                            </button>
                        </div>
                    </figure>
                ))}

                {room > 0 && (
                    <button
                        type="button"
                        onClick={() => input.current?.click()}
                        onDragOver={(event) => {
                            event.preventDefault();
                            setDropping(true);
                        }}
                        onDragLeave={() => setDropping(false)}
                        onDrop={dropFiles}
                        disabled={working}
                        className={cn(
                            'grid aspect-square place-items-center rounded-xl border-2 border-dashed p-3 text-center text-sm font-bold transition hover:border-brand hover:text-brand-text',
                            dropping
                                ? 'border-brand bg-brand/10 text-brand-text'
                                : 'border-line text-ink-soft',
                        )}
                    >
                        <span>
                            {working ? (
                                <Spinner />
                            ) : (
                                <ImagePlus
                                    size={28}
                                    aria-hidden="true"
                                    className="mx-auto mb-2"
                                />
                            )}
                            {t('Add images')}
                        </span>
                    </button>
                )}
            </div>

            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                multiple
                onChange={choose}
                aria-label={t('Add images')}
                className="sr-only"
            />

            {errors.map((error) => (
                <p key={error} className="mt-2 text-sm text-rose-700">
                    {error}
                </p>
            ))}
        </section>
    );
}
