<?php

namespace App\Services;

use App\Exceptions\ShopException;
use App\Models\Product;
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

    public function store(Product $p, UploadedFile $file): string
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
        $bytes = ob_get_clean();

        $path = "products/{$p->id}-".($p->image_version + 1).'.webp';
        Storage::disk('public')->put($path, $bytes);
        if ($p->image_path) Storage::disk('public')->delete($p->image_path);
        $p->update(['image_path' => $path, 'image_version' => $p->image_version + 1]);
        return $path;
    }
}
