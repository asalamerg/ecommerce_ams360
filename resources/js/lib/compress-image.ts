const MAX_SIDE = 1600;
const QUALITY = 0.85;

/**
 * Shrinks a photo in the browser before upload, so large phone photos pass the
 * server's upload limit and the store stays fast. Anything the browser cannot
 * decode is returned unchanged for the server to accept or refuse.
 */
export async function compressImage(
    file: File,
    maxSide = MAX_SIDE,
): Promise<File> {
    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(
            1,
            maxSide / Math.max(bitmap.width, bitmap.height),
        );
        const canvas = document.createElement('canvas');

        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas
            .getContext('2d')
            ?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, 'image/webp', QUALITY),
        );

        if (!blob || blob.size >= file.size) {
            return file;
        }

        const name = file.name.replace(/\.[^.]+$/, '');
        const extension = blob.type === 'image/webp' ? 'webp' : 'png';

        return new File([blob], `${name}.${extension}`, { type: blob.type });
    } catch {
        return file;
    }
}
