<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->manager = User::factory()->create();
        $this->manager->forceFill(['role' => UserRole::ProductManager])->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        static $number = 0;
        $number++;

        return [
            'category_id' => null,
            'sku' => null,
            'price' => '10.0000',
            'currency' => 'ILS',
            'is_active' => true,
            'is_featured' => false,
            'price_is_estimate' => false,
            'translations' => [
                'ar' => ['name' => 'هاتف', 'slug' => "phone-ar-{$number}"],
                'en' => ['name' => 'Phone', 'slug' => "phone-en-{$number}"],
                'he' => ['name' => 'טלפון', 'slug' => "phone-he-{$number}"],
            ],
            ...$overrides,
        ];
    }

    private function photo(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 400, 400);
    }

    private function productWithImages(int $count = 2): Product
    {
        $this->actingAs($this->manager)->post('/admin/products', $this->payload([
            'sync_images' => true,
            'image_order' => array_map(fn (int $index): string => "new-{$index}", range(0, $count - 1)),
            'new_images' => array_map(fn (): UploadedFile => $this->photo(), range(1, $count)),
        ]))->assertSessionHasNoErrors();

        return Product::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $images
     */
    private function update(Product $product, array $images)
    {
        $translations = $product->translations->mapWithKeys(fn ($translation) => [
            $translation->locale => ['name' => $translation->name, 'slug' => $translation->slug],
        ])->all();

        return $this->actingAs($this->manager)->post("/admin/products/{$product->id}", [
            ...$this->payload(['translations' => $translations]),
            '_method' => 'put',
            'sync_images' => true,
            ...$images,
        ]);
    }

    public function test_images_can_be_added_when_creating_a_product(): void
    {
        $product = $this->productWithImages(2);

        $this->assertCount(2, $product->images);
        Storage::disk('public')->assertExists($product->images->first()->path);
        $this->assertSame([0, 1], $product->images->pluck('position')->all());
    }

    public function test_the_edit_form_receives_existing_images(): void
    {
        $product = $this->productWithImages(2);

        $this->get("/admin/products/{$product->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->has('product.images', 2)
            ->where('product.images.0.id', $product->images->first()->id));
    }

    public function test_uploaded_names_are_replaced_with_random_ones(): void
    {
        $this->actingAs($this->manager)->post('/admin/products', $this->payload([
            'sync_images' => true,
            'image_order' => ['new-0'],
            'new_images' => [$this->photo('../../evil name.png')],
        ]));

        $path = ProductImage::query()->sole()->path;

        $this->assertStringNotContainsString('evil', $path);
        $this->assertMatchesRegularExpression('#^products/\d+/[A-Za-z0-9]{40}\.webp$#', $path);
    }

    public function test_images_can_be_reordered_and_the_first_becomes_the_main_image(): void
    {
        $product = $this->productWithImages(2);
        [$first, $second] = $product->images->all();

        $this->update($product, ['image_order' => ["existing-{$second->id}", "existing-{$first->id}"]])
            ->assertSessionHasNoErrors();

        $this->assertSame($second->id, $product->fresh()->images->first()->id);
    }

    public function test_removing_an_image_deletes_its_file(): void
    {
        $product = $this->productWithImages(2);
        [$first, $second] = $product->images->all();

        $this->update($product, ['image_order' => ["existing-{$first->id}"]])->assertSessionHasNoErrors();

        $this->assertModelMissing($second);
        Storage::disk('public')->assertMissing($second->path);
        Storage::disk('public')->assertExists($first->path);
    }

    public function test_removing_images_never_touches_another_product(): void
    {
        $other = $this->productWithImages(2);
        $product = $this->productWithImages(2);

        $this->update($product, [])->assertSessionHasNoErrors();

        $this->assertCount(2, $other->fresh()->images);
        $this->assertCount(0, $product->fresh()->images);
    }

    public function test_every_image_can_be_removed(): void
    {
        $product = $this->productWithImages(1);

        $this->update($product, [])->assertSessionHasNoErrors();

        $this->assertCount(0, $product->fresh()->images);
    }

    public function test_saving_without_the_image_section_leaves_images_alone(): void
    {
        $product = $this->productWithImages(2);
        $translations = $product->translations->mapWithKeys(fn ($translation) => [
            $translation->locale => ['name' => $translation->name, 'slug' => $translation->slug],
        ])->all();

        $this->actingAs($this->manager)
            ->put("/admin/products/{$product->id}", $this->payload(['translations' => $translations]))
            ->assertSessionHasNoErrors();

        $this->assertCount(2, $product->fresh()->images);
    }

    public function test_a_file_shared_with_another_product_is_kept(): void
    {
        $product = $this->productWithImages(1);
        $image = $product->images->first();
        $other = $this->productWithImages(1);
        $other->images()->create(['path' => $image->path, 'position' => 1]);

        $this->update($product, [])->assertSessionHasNoErrors();

        Storage::disk('public')->assertExists($image->path);
    }

    public function test_another_products_image_cannot_be_taken_or_removed(): void
    {
        $victim = $this->productWithImages(1);
        $stolen = $victim->images->first();
        $product = $this->productWithImages(1);

        $this->update($product, ['image_order' => ["existing-{$stolen->id}"]])->assertSessionHasNoErrors();

        $this->assertSame($victim->id, $stolen->fresh()->product_id);
        $this->assertCount(0, $product->fresh()->images);
    }

    public function test_files_that_are_not_images_are_refused(): void
    {
        $files = [
            UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]);'),
            UploadedFile::fake()->createWithContent('shell.png', '<?php system($_GET["c"]);'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->create('file.pdf', 10, 'application/pdf'),
        ];

        foreach ($files as $file) {
            $this->actingAs($this->manager)->post('/admin/products', $this->payload([
                'sync_images' => true,
                'image_order' => ['new-0'],
                'new_images' => [$file],
            ]))->assertSessionHasErrors('new_images.0');
        }

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_oversized_and_tiny_images_are_refused(): void
    {
        foreach ([UploadedFile::fake()->image('big.png', 400, 400)->size(6000), UploadedFile::fake()->image('tiny.png', 20, 20)] as $file) {
            $this->actingAs($this->manager)->post('/admin/products', $this->payload([
                'sync_images' => true,
                'image_order' => ['new-0'],
                'new_images' => [$file],
            ]))->assertSessionHasErrors('new_images.0');
        }
    }

    public function test_a_product_cannot_have_more_than_eight_images(): void
    {
        $this->actingAs($this->manager)->post('/admin/products', $this->payload([
            'sync_images' => true,
            'image_order' => array_map(fn (int $index): string => "new-{$index}", range(0, 8)),
            'new_images' => array_map(fn (): UploadedFile => $this->photo(), range(0, 8)),
        ]))->assertSessionHasErrors('image_order');
    }

    public function test_only_catalog_staff_can_change_images(): void
    {
        $product = $this->productWithImages(1);

        foreach ([UserRole::InventoryManager, UserRole::SupportAgent, UserRole::Customer] as $role) {
            $user = User::factory()->create();
            $user->forceFill(['role' => $role])->save();

            $this->actingAs($user)
                ->post("/admin/products/{$product->id}", ['_method' => 'put', 'sync_images' => true])
                ->assertForbidden();
        }

        $this->assertCount(1, $product->fresh()->images);
    }

    public function test_the_change_is_recorded_in_the_activity_log(): void
    {
        $product = $this->productWithImages(1);

        $this->assertDatabaseHas('catalog_activities', ['action' => 'product.created', 'subject_id' => $product->id]);
        $this->assertCount(1, data_get(json_decode((string) \DB::table('catalog_activities')->latest('id')->value('changes'), true), 'after.images'));
    }

    private function upload(UploadedFile $file): string
    {
        $this->actingAs($this->manager)->post('/admin/products', $this->payload([
            'sync_images' => true,
            'image_order' => ['new-0'],
            'new_images' => [$file],
        ]))->assertSessionHasNoErrors();

        return (string) Storage::disk('public')->get(ProductImage::query()->latest('id')->firstOrFail()->path);
    }

    /**
     * A real JPEG of the given size with an EXIF block holding a rotation flag and
     * a camera note, the way phones save photos.
     */
    private function jpegWithExif(int $width, int $height, int $orientation): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        $note = "SECRET-GPS-NOTE\0";
        $ifd = pack('v', 2)
            .pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
            .pack('vvVV', 0x010E, 2, strlen($note), 8 + 2 + 24 + 4)
            .pack('V', 0);
        $exif = "Exif\0\0".'II*'."\0".pack('V', 8).$ifd.$note;
        $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $path = tempnam(sys_get_temp_dir(), 'exif');
        file_put_contents($path, substr($jpeg, 0, 2).$segment.substr($jpeg, 2));

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    public function test_uploads_are_redrawn_as_webp_so_hidden_payloads_do_not_survive(): void
    {
        $file = UploadedFile::fake()->image('photo.png', 300, 300);
        file_put_contents($file->getRealPath(), '<?php system($_GET["c"]); ?>', FILE_APPEND);

        $stored = $this->upload($file);

        $this->assertSame('image/webp', getimagesizefromstring($stored)['mime']);
        $this->assertStringNotContainsString('<?php', $stored);
    }

    public function test_photo_metadata_is_removed_and_the_rotation_is_kept(): void
    {
        $file = $this->jpegWithExif(200, 100, 6);
        $this->assertSame(6, exif_read_data($file->getRealPath())['Orientation']);

        $stored = $this->upload($file);
        $size = getimagesizefromstring($stored);

        $this->assertStringNotContainsString('SECRET-GPS-NOTE', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertSame([100, 200], [$size[0], $size[1]]);
    }

    public function test_large_images_are_scaled_down_and_huge_ones_refused(): void
    {
        $size = getimagesizefromstring($this->upload(UploadedFile::fake()->image('wide.png', 3200, 1200)));
        $this->assertSame([1600, 600], [$size[0], $size[1]]);

        $this->actingAs($this->manager)->post('/admin/products', $this->payload([
            'sync_images' => true,
            'image_order' => ['new-0'],
            'new_images' => [UploadedFile::fake()->image('huge.png', 5200, 200)],
        ]))->assertSessionHasErrors('new_images.0');
    }

    public function test_transparent_images_stay_transparent(): void
    {
        $image = imagecreatetruecolor(120, 120);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $path = tempnam(sys_get_temp_dir(), 'png');
        imagepng($image, $path);

        $stored = imagecreatefromstring($this->upload(new UploadedFile($path, 'logo.png', 'image/png', null, true)));

        $this->assertSame(127, (imagecolorat($stored, 5, 5) >> 24) & 0x7F);
    }
}
