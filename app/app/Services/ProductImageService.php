<?php

namespace App\Services;

use App\Exceptions\ShopException;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Product photo upload. The file is decoded and re-encoded as WebP (strips metadata, defeats disguised
 * uploads) and resized to at most 1200px on the long side. Stored on the public disk.
 */
class ProductImageService
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MAX_SIDE = 1200;
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Decode, validate, resize and re-encode to WebP bytes. */
    private function encode(UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_BYTES) throw new ShopException('VALIDATION_FAILED', 'Image is larger than 8 MB');
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info['mime'], self::TYPES, true)) throw new ShopException('VALIDATION_FAILED', 'Only JPEG, PNG or WebP images are accepted');

        $src = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($file->getRealPath()),
            'image/png' => @imagecreatefrompng($file->getRealPath()),
            'image/webp' => @imagecreatefromwebp($file->getRealPath()),
        };
        if (! $src) throw new ShopException('VALIDATION_FAILED', 'Image could not be read');

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagewebp($dst, null, 82);
        return (string) ob_get_clean();
    }

    public function store(Product $p, UploadedFile $file): string
    {
        $bytes = $this->encode($file);
        $path = "products/{$p->id}-".($p->image_version + 1).'.webp';
        Storage::disk('public')->put($path, $bytes);
        if ($p->image_path) Storage::disk('public')->delete($p->image_path);
        $p->update(['image_path' => $path, 'image_version' => $p->image_version + 1]);
        return $path;
    }

    public const MAX_EXTRA = 5;

    /** Additional gallery photo (up to 5 besides the cover). */
    public function addExtra(Product $p, UploadedFile $file): ProductImage
    {
        if ($p->gallery()->count() >= self::MAX_EXTRA) throw new ShopException('CONFLICT', 'A product can have at most '.self::MAX_EXTRA.' extra photos');
        $bytes = $this->encode($file);
        $path = "products/{$p->id}-x".bin2hex(random_bytes(4)).'.webp';
        Storage::disk('public')->put($path, $bytes);
        return $p->gallery()->create(['path' => $path, 'sort_order' => (int) $p->gallery()->max('sort_order') + 1]);
    }

    public function removeExtra(ProductImage $img): void
    {
        Storage::disk('public')->delete($img->path);
        $img->delete();
    }
}
