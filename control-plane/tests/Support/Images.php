<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Real image bytes for upload tests, made without GD (the `php` image has
 * none). The JPEG, WebP and GIF are 2×2 pixels.
 */
final class Images
{
    protected const string JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBxdWFsaXR5ID0gNTAK/9sAQwAQCwwODAoQDg0OEhEQExgoGhgWFhgxIyUdKDozPTw5Mzg3QEhcTkBEV0U3OFBtUVdfYmdoZz5NcXlwZHhcZWdj/9sAQwEREhIYFRgvGhovY0I4QmNjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2Nj/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A8/ooooA//9k=';

    protected const string WEBP = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAIAAsBMJaQAA3AA/vgfgAA=';

    protected const string GIF = 'R0lGODdhAgACAIAAAAAAAAAAACwAAAAAAgACAAACAoRRADs=';

    /** A valid one-bit greyscale PNG of any size. A few dozen bytes, whatever the dimensions. */
    public static function png(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\0".str_repeat("\0", intdiv($width + 7, 8));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 1, 0, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat($row, $height)))
            .$chunk('IEND', '');
    }

    public static function jpeg(): string
    {
        return (string) base64_decode(self::JPEG, true);
    }

    public static function webp(): string
    {
        return (string) base64_decode(self::WEBP, true);
    }

    public static function gif(): string
    {
        return (string) base64_decode(self::GIF, true);
    }

    public static function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
    }

    /** The same image, grown to $length bytes with trailing zero bytes. Use it with a PNG. */
    public static function padded(string $bytes, int $length): string
    {
        return str_pad($bytes, $length, "\0");
    }

    /** An upload as a browser sends it: the name and the type are whatever the client claims. */
    public static function upload(string $bytes, string $clientName = 'image.png', string $clientType = 'image/png'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'hostmesh-image-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $clientName, $clientType, null, true);
    }
}
