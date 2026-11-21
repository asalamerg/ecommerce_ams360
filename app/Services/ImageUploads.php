<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every image uploaded in the admin panel goes through here.
 *
 * Uploads are never stored as sent. Each one is decoded and drawn again as a new
 * WebP file with a random name, so nothing from the original survives: no hidden
 * payload appended to the pixels (polyglot files), no EXIF data such as the GPS
 * position of the phone that took the photo, and no uploaded file name.
 */
class ImageUploads
{
    public const string DISK = 'public';

    /** Decoding needs about 4 bytes per pixel, so this keeps one image near 100 MB. */
    public const int MAX_UPLOAD_SIDE = 5000;

    public const int MAX_UPLOAD_KILOBYTES = 5120;

    private const int WEBP_QUALITY = 85;

    /**
     * Product cards are at most about 300 CSS pixels wide, so 600 pixels stays
     * sharp on high-density phone screens at a fraction of the full image size.
     */
    public const int THUMBNAIL_SIDE = 600;

    /** Category circles are about 100 CSS pixels; 320 covers high-density screens. */
    public const int CATEGORY_THUMBNAIL_SIDE = 320;

    /**
     * Validation rules for one uploaded image.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:'.self::MAX_UPLOAD_KILOBYTES,
            'dimensions:min_width=100,min_height=100,max_width='.self::MAX_UPLOAD_SIDE.',max_height='.self::MAX_UPLOAD_SIDE,
        ];
    }

    /**
     * Re-encodes the upload and writes it under $directory. Returns the path on
     * the public disk. If the surrounding transaction rolls back, the file is
     * removed again, so a failed save leaves nothing behind.
     *
     * @param  string  $errorKey  The form field to report an undecodable file under.
     * @param  bool  $trim  Cut away the empty margin around the picture (logos).
     */
    public function store(UploadedFile $upload, string $directory, int $maxSide, string $errorKey, bool $trim = false): string
    {
        $path = trim($directory, '/').'/'.Str::random(40).'.webp';

        if (! Storage::disk(self::DISK)->put($path, $this->webp($upload, $maxSide, $errorKey, $trim))) {
            throw ValidationException::withMessages([$errorKey => __('validation.uploaded', ['attribute' => __('image')])]);
        }

        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(fn () => Storage::disk(self::DISK)->delete($path));
        }

        return $path;
    }

    /**
     * Deletes a file once the current transaction has committed, so a failed save
     * never removes an image that is still in use.
     */
    public function deleteAfterCommit(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));
    }

    /**
     * Writes a smaller copy of an image already on the public disk, next to it as
     * {name}.w600.webp, and returns its path. Returns null when the image is
     * already small enough to be its own thumbnail or cannot be read. Removed
     * again if the surrounding transaction rolls back.
     */
    public function thumbnail(string $path, int $side = self::THUMBNAIL_SIDE): ?string
    {
        $disk = Storage::disk(self::DISK);
        $contents = $disk->exists($path) ? $disk->get($path) : null;
        $image = $contents === null ? false : @imagecreatefromstring($contents);

        if ($image === false || max(imagesx($image), imagesy($image)) <= $side) {
            return null;
        }

        $thumbnail = self::thumbnailPath($path, $side);

        if (! $disk->put($thumbnail, $this->encode($this->fit($image, $side)))) {
            return null;
        }

        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(fn () => $disk->delete($thumbnail));
        }

        return $thumbnail;
    }

    public static function thumbnailPath(string $path, int $side = self::THUMBNAIL_SIDE): string
    {
        $info = pathinfo($path);
        $directory = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'].'/';

        return $directory.$info['filename'].".w{$side}.webp";
    }

    private function webp(UploadedFile $upload, int $maxSide, string $errorKey, bool $trim = false): string
    {
        $contents = file_get_contents($upload->getRealPath());
        $image = $contents === false ? false : @imagecreatefromstring($contents);

        if ($image === false) {
            throw ValidationException::withMessages([$errorKey => __('validation.image', ['attribute' => __('image')])]);
        }

        // Resized first, so the trim scans at most $maxSide pixels a side.
        $image = $this->fit($this->upright($image, $upload), $maxSide);

        return $this->encode($trim ? $this->trimMargin($image) : $image);
    }

    /**
     * Logos arrive with all kinds of empty borders, so the same box shows one
     * tiny and the next one huge. The border is whatever the top-left corner
     * is: transparency, or a plain color within a small tolerance. A crop that
     * would leave almost nothing is not applied.
     */
    private function trimMargin(GdImage $image): GdImage
    {
        imagepalettetotruecolor($image);
        $corner = imagecolorat($image, 0, 0);

        // A transparent border is found by transparency alone: GD's threshold
        // crop compares colors, so a dark logo on transparent black was cut away.
        $box = (($corner >> 24) & 0x7F) >= 120
            ? $this->opaqueBox($image)
            : null;

        $cropped = $box !== null
            ? imagecrop($image, $box)
            : imagecropauto($image, IMG_CROP_THRESHOLD, 6.0, (int) $corner);

        if ($cropped === false || imagesx($cropped) < 16 || imagesy($cropped) < 16) {
            return $image;
        }

        return $cropped;
    }

    /**
     * The smallest rectangle holding every pixel that is not (almost) fully
     * transparent, or null when there is nothing to cut.
     *
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private function opaqueBox(GdImage $image): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        [$left, $top, $right, $bottom] = [$width, $height, -1, -1];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 120) {
                    $left = min($left, $x);
                    $right = max($right, $x);
                    $top = min($top, $y);
                    $bottom = max($bottom, $y);
                }
            }
        }

        if ($right < 0 || ($left === 0 && $top === 0 && $right === $width - 1 && $bottom === $height - 1)) {
            return null;
        }

        return ['x' => $left, 'y' => $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1];
    }

    private function encode(GdImage $image): string
    {
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, self::WEBP_QUALITY);

        return (string) ob_get_clean();
    }

    /**
     * Phones save photos sideways and record the rotation in EXIF. That data is
     * dropped on re-encoding, so the rotation is applied to the pixels first.
     */
    private function upright(GdImage $image, UploadedFile $upload): GdImage
    {
        if (! function_exists('exif_read_data') || $upload->getMimeType() !== 'image/jpeg') {
            return $image;
        }

        $exif = @exif_read_data($upload->getRealPath());
        $angle = match (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private function fit(GdImage $image, int $maxSide): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxSide / max($width, $height));

        if ($scale >= 1) {
            return $image;
        }

        return imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))) ?: $image;
    }
}
