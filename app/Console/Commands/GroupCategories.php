<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Support\StorefrontCache;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts the imported categories ("Yesido Car Chargers", "KLGO Car Phone
 * Holders"...) under main sections by product type, so the storefront shows
 * ten sections instead of seventy. Brands have their own pages, so the type is
 * what customers browse by.
 *
 * Matching uses the English name. Safe to run again: existing sections are
 * reused and already grouped categories are left as they are. Undo a section
 * by deleting it in the admin panel; its categories become main ones again.
 */
class GroupCategories extends Command
{
    protected $signature = 'store:group-categories {--dry-run : Show the grouping without changing anything}';

    protected $description = 'Group the imported categories under main sections by product type';

    /**
     * @var list<array{slug: string, names: array{ar: string, en: string, he: string}, match: string}>
     */
    private const array SECTIONS = [
        ['slug' => 'audio-speakers', 'names' => ['ar' => 'مكبرات صوت', 'en' => 'Speakers', 'he' => 'רמקולים'], 'match' => '/(computer|bluetooth) speakers$/i'],
        ['slug' => 'headphones-earphones', 'names' => ['ar' => 'سماعات رأس وأذن', 'en' => 'Headphones & Earphones', 'he' => 'אוזניות'], 'match' => '/(headphones|gaming headsets|earphones)$/i'],
        ['slug' => 'computer-accessories', 'names' => ['ar' => 'ملحقات الكمبيوتر', 'en' => 'Computer Accessories', 'he' => 'אביזרי מחשב'], 'match' => '/(mice|keyboards|microphones|webcams & cameras)$/i'],
        ['slug' => 'gaming', 'names' => ['ar' => 'إكسسوارات الألعاب', 'en' => 'Gaming', 'he' => 'גיימינג'], 'match' => '/gaming accessories$/i'],
        ['slug' => 'security-smart-home', 'names' => ['ar' => 'المراقبة والمنزل الذكي', 'en' => 'Security & Smart Home', 'he' => 'אבטחה ובית חכם'], 'match' => '/(security cameras|smart doorbells|smart gadgets)$/i'],
        ['slug' => 'networking-power', 'names' => ['ar' => 'الشبكات والطاقة', 'en' => 'Networking & Power', 'he' => 'רשת וחשמל'], 'match' => '/(networking & routers|power supplies & ups|power banks)$/i'],
        ['slug' => 'tv-entertainment', 'names' => ['ar' => 'التلفاز والترفيه', 'en' => 'TV & Entertainment', 'he' => 'טלוויזיה ובידור'], 'match' => '/(tv boxes & receivers|remote controls)$/i'],
        ['slug' => 'cables', 'names' => ['ar' => 'كوابل ومحولات', 'en' => 'Cables & Adapters', 'he' => 'כבלים ומתאמים'], 'match' => '/cables & adapters$/i'],
        ['slug' => 'car-accessories', 'names' => ['ar' => 'إكسسوارات السيارة', 'en' => 'Car Accessories', 'he' => 'אביזרים לרכב'], 'match' => '/(car phone holders|car chargers)$/i'],
        ['slug' => 'personal-care', 'names' => ['ar' => 'العناية الشخصية', 'en' => 'Personal Care', 'he' => 'טיפוח אישי'], 'match' => '/massage & personal care$/i'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $categories = Category::query()->with('translations')->withCount('products')->orderBy('id')->get();
        $english = fn (Category $category): string => (string) $category->translations->firstWhere('locale', 'en')?->name;
        $rows = [];

        DB::transaction(function () use ($categories, $english, $dryRun, &$rows): void {
            foreach (self::SECTIONS as $index => $section) {
                $section['position'] = ($index + 1) * 10;
                $parent = $this->section($section, $categories, $dryRun);
                $members = $categories->filter(fn (Category $category): bool => $category->id !== $parent?->id
                    && $category->parent_id === null
                    && ! $categories->contains('parent_id', $category->id)
                    && preg_match($section['match'], $english($category)) === 1);

                foreach ($members as $member) {
                    $rows[] = [$section['names']['ar'], $english($member), $member->products_count];

                    if (! $dryRun && $parent !== null) {
                        $member->update(['parent_id' => $parent->id]);
                        $member->parent_id = $parent->id;
                    }
                }

                // A new section borrows the picture of its biggest category.
                $cover = $members->sortByDesc('products_count')->first(fn (Category $member): bool => $member->image_path !== null);

                if (! $dryRun && $parent !== null && $parent->image_path === null && $cover !== null) {
                    $parent->update(['image_path' => $cover->image_path, 'thumb_path' => $cover->thumb_path]);
                }
            }

            if ($dryRun) {
                DB::rollBack();
                DB::beginTransaction();
            }
        });

        $this->table(['Main section', 'Category', 'Products'], $rows);
        $this->info(($dryRun ? 'Would group ' : 'Grouped ').count($rows).' categories.');

        if (! $dryRun) {
            StorefrontCache::flush();
        }

        return self::SUCCESS;
    }

    /**
     * The section's category: an existing main one with the same English slug
     * (a re-run, or "Cables & Adapters" that already exists), or a new one.
     *
     * @param  array{slug: string, names: array{ar: string, en: string, he: string}, match: string, position: int}  $section
     * @param  Collection<int, Category>  $categories
     */
    private function section(array $section, $categories, bool $dryRun): ?Category
    {
        $existing = CategoryTranslation::query()->where('locale', 'en')->where('slug', $section['slug'])->value('category_id');

        if ($existing !== null) {
            return $categories->firstWhere('id', (int) $existing);
        }

        if ($dryRun) {
            return null;
        }

        $parent = Category::query()->create(['is_active' => true, 'position' => $section['position']]);

        foreach ($section['names'] as $locale => $name) {
            $parent->translations()->create([
                'locale' => $locale,
                'name' => $name,
                'slug' => $locale === 'en' ? $section['slug'] : "{$section['slug']}-{$locale}",
            ]);
        }

        return $parent;
    }
}
