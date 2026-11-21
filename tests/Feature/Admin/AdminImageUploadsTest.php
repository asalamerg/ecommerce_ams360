<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminImageUploadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function categoryPayload(array $overrides = []): array
    {
        return [
            'is_active' => true,
            'position' => 1,
            'translations' => [
                'ar' => ['name' => 'هواتف', 'slug' => 'phones-ar'],
                'en' => ['name' => 'Phones', 'slug' => 'phones-en'],
                'he' => ['name' => 'טלפונים', 'slug' => 'phones-he'],
            ],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function slidePayload(array $overrides = []): array
    {
        return [
            'image_url' => '',
            'title_ar' => 'عروض',
            'title_en' => 'Offers',
            'title_he' => 'מבצעים',
            'subtitle_ar' => '',
            'subtitle_en' => '',
            'subtitle_he' => '',
            'link_url' => '',
            'sort_order' => 1,
            'is_active' => true,
            ...$overrides,
        ];
    }

    private function decoded(string $path): array
    {
        return getimagesizefromstring((string) Storage::disk('public')->get($path)) ?: [];
    }

    public function test_a_category_image_is_re_encoded_shrunk_and_shown_in_the_store(): void
    {
        $manager = $this->userWithRole(UserRole::ProductManager);
        $file = UploadedFile::fake()->image('cat.png', 2000, 1000);
        file_put_contents($file->getRealPath(), '<?php echo 1;', FILE_APPEND);

        $this->actingAs($manager)
            ->post('/admin/categories', $this->categoryPayload(['image' => $file]))
            ->assertSessionHasNoErrors();

        $category = Category::query()->sole();
        $this->assertMatchesRegularExpression("#^categories/{$category->id}/[A-Za-z0-9]{40}\\.webp$#", (string) $category->image_path);
        $stored = (string) Storage::disk('public')->get((string) $category->image_path);
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertSame([800, 400, 'image/webp'], [...array_slice($this->decoded((string) $category->image_path), 0, 2), $this->decoded((string) $category->image_path)['mime']]);

        $this->get("/admin/categories/{$category->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('category.image_url', "/storage/{$category->image_path}"));
    }

    public function test_replacing_or_removing_a_category_image_deletes_only_uploaded_files(): void
    {
        $manager = $this->userWithRole(UserRole::ProductManager);
        $this->actingAs($manager)->post('/admin/categories', $this->categoryPayload(['image' => UploadedFile::fake()->image('a.png', 300, 300)]));
        $category = Category::query()->sole();
        $first = (string) $category->image_path;

        $this->actingAs($manager)
            ->post("/admin/categories/{$category->id}", $this->categoryPayload(['_method' => 'put', 'image' => UploadedFile::fake()->image('b.png', 300, 300)]))
            ->assertSessionHasNoErrors();
        $second = (string) $category->fresh()->image_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->actingAs($manager)->post("/admin/categories/{$category->id}", $this->categoryPayload(['_method' => 'put', 'remove_image' => true]));
        $this->assertNull($category->fresh()->image_path);
        Storage::disk('public')->assertMissing($second);

        // Imported catalog images are never deleted from here.
        Storage::disk('public')->put('catalog/categories/phones.webp', 'x');
        $category->update(['image_path' => 'catalog/categories/phones.webp']);
        $this->actingAs($manager)->post("/admin/categories/{$category->id}", $this->categoryPayload(['_method' => 'put', 'remove_image' => true]));
        Storage::disk('public')->assertExists('catalog/categories/phones.webp');
    }

    public function test_saving_a_category_without_touching_the_image_keeps_it(): void
    {
        $manager = $this->userWithRole(UserRole::ProductManager);
        $this->actingAs($manager)->post('/admin/categories', $this->categoryPayload(['image' => UploadedFile::fake()->image('a.png', 300, 300)]));
        $category = Category::query()->sole();
        $path = $category->image_path;

        $this->actingAs($manager)->post("/admin/categories/{$category->id}", $this->categoryPayload(['_method' => 'put', 'position' => 9]))->assertSessionHasNoErrors();

        $this->assertSame($path, $category->fresh()->image_path);
        $this->assertSame(9, $category->fresh()->position);
    }

    public function test_category_uploads_refuse_files_that_are_not_images(): void
    {
        $manager = $this->userWithRole(UserRole::ProductManager);

        $this->actingAs($manager)
            ->post('/admin/categories', $this->categoryPayload(['image' => UploadedFile::fake()->createWithContent('x.png', '<?php system("id");')]))
            ->assertSessionHasErrors('image');

        $this->assertDatabaseCount('categories', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_hero_slides_take_an_uploaded_image(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);

        $this->actingAs($admin)
            ->post('/admin/modules/hero-slides', $this->slidePayload(['image_url_file' => UploadedFile::fake()->image('hero.jpg', 3000, 1200)]))
            ->assertSessionHasNoErrors();

        $url = (string) DB::table('hero_slides')->value('image_url');
        $this->assertMatchesRegularExpression('#^/storage/uploads/hero_slides/[A-Za-z0-9]{40}\.webp$#', $url);
        $this->assertSame(2400, $this->decoded(substr($url, strlen('/storage/')))[0]);

        $this->get('/ar')->assertInertia(fn (Assert $page) => $page->where('slides.0.image_url', $url));
    }

    public function test_a_required_slide_image_cannot_be_left_empty(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);

        $this->actingAs($admin)->post('/admin/modules/hero-slides', $this->slidePayload())->assertSessionHasErrors('image_url');

        $this->assertDatabaseCount('hero_slides', 0);
    }

    public function test_replacing_a_module_image_deletes_the_old_upload_after_saving(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        $this->actingAs($admin)->post('/admin/modules/hero-slides', $this->slidePayload(['image_url_file' => UploadedFile::fake()->image('a.jpg', 400, 300)]));
        $id = (int) DB::table('hero_slides')->value('id');
        $old = (string) DB::table('hero_slides')->value('image_url');

        // Saving with the current URL and no file keeps the image.
        $this->actingAs($admin)->post("/admin/modules/hero-slides/{$id}", $this->slidePayload(['_method' => 'put', 'image_url' => $old]))->assertSessionHasNoErrors();
        $this->assertSame($old, DB::table('hero_slides')->value('image_url'));

        $this->actingAs($admin)
            ->post("/admin/modules/hero-slides/{$id}", $this->slidePayload(['_method' => 'put', 'image_url' => $old, 'image_url_file' => UploadedFile::fake()->image('b.jpg', 400, 300)]))
            ->assertSessionHasNoErrors();

        $new = (string) DB::table('hero_slides')->value('image_url');
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing(substr($old, strlen('/storage/')));
        Storage::disk('public')->assertExists(substr($new, strlen('/storage/')));
    }

    public function test_optional_module_images_can_be_removed_and_links_elsewhere_are_never_deleted(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        $brand = [
            'slug' => 'fantech', 'name_ar' => 'فانتيك', 'name_en' => 'Fantech', 'name_he' => 'Fantech',
            'description_ar' => '', 'description_en' => '', 'description_he' => '', 'sort_order' => 1, 'is_active' => true,
        ];

        $this->actingAs($admin)->post('/admin/modules/brands', [...$brand, 'logo_url' => '', 'logo_url_file' => UploadedFile::fake()->image('logo.png', 1200, 1200)])->assertSessionHasNoErrors();
        $id = (int) DB::table('brands')->value('id');
        $logo = (string) DB::table('brands')->value('logo_url');
        $this->assertSame(600, $this->decoded(substr($logo, strlen('/storage/')))[0]);

        $this->actingAs($admin)->post("/admin/modules/brands/{$id}", [...$brand, '_method' => 'put', 'logo_url' => ''])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('brands')->value('logo_url'));
        Storage::disk('public')->assertMissing(substr($logo, strlen('/storage/')));

        $this->actingAs($admin)->post("/admin/modules/brands/{$id}", [...$brand, '_method' => 'put', 'logo_url' => 'https://cdn.example/logo.png'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/modules/brands/{$id}", [...$brand, '_method' => 'put', 'logo_url' => ''])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('brands')->value('logo_url'));
    }

    public function test_image_links_must_be_uploads_or_https(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);

        foreach (['http://insecure.example/a.png', 'javascript:alert(1)', '/storage/../.env', '/storage/uploads/hero_slides/../../../.env'] as $link) {
            $this->actingAs($admin)
                ->post('/admin/modules/hero-slides', $this->slidePayload(['image_url' => $link]))
                ->assertSessionHasErrors('image_url');
        }

        $this->assertDatabaseCount('hero_slides', 0);
    }

    public function test_module_uploads_refuse_files_that_are_not_images_and_leave_nothing_behind(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);

        $this->actingAs($admin)
            ->post('/admin/modules/hero-slides', $this->slidePayload(['image_url_file' => UploadedFile::fake()->createWithContent('x.jpg', 'not an image')]))
            ->assertSessionHasErrors('image_url_file');

        $this->assertDatabaseCount('hero_slides', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_form_describes_image_fields(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);

        $this->actingAs($admin)->get('/admin/modules/hero-slides/create')
            ->assertInertia(fn (Assert $page) => $page
                ->where('module.fields.0.type', 'image')
                ->where('module.fields.0.max_side', 2400)
                ->where('module.fields.0.wide', true));
    }
}
