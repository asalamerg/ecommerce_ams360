<?php

namespace App\Http\Controllers;

use App\Rules\TurnstileToken;
use App\Services\ImageUploads;
use App\Services\StaffAlerts;
use App\Support\ProductCardData;
use App\Support\StorefrontFooter;
use App\Support\StorefrontSeo;
use App\Support\StorePolicies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer side of the admin's support screens: contact messages, repair
 * requests and support tickets are written here, and SIM plans and branches
 * are shown here. Every public form has the bot check and its own rate limit.
 */
class CustomerServiceController extends Controller
{
    /** Kinds of device a repair request can be for. */
    public const array DEVICE_TYPES = ['phone', 'tablet', 'laptop', 'watch', 'headphones', 'other'];

    private const int MAX_REPAIR_PHOTOS = 3;

    /** Photos of a device: big enough to see damage, small enough to store. */
    private const int REPAIR_PHOTO_SIDE = 1600;

    /** Digits, spaces and the usual phone signs, 7 to 20 characters. */
    private const string PHONE_PATTERN = '/^\+?[0-9\s\-()]{7,20}$/';

    public function contact(string $locale): Response
    {
        return Inertia::render('storefront/contact', [
            'contact' => StorefrontFooter::for($locale),
            'branches' => $this->branches($locale),
            'policies' => StorePolicies::for($locale),
            'turnstileSiteKey' => TurnstileToken::siteKey(),
            'seo' => StorefrontSeo::servicePage($locale, 'storefront.contact', __('ui.service.contact_title'), __('ui.service.contact_intro')),
        ]);
    }

    public function sendContact(Request $request, string $locale, StaffAlerts $alerts): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'required_without:email', 'string', 'regex:'.self::PHONE_PATTERN],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:255'],
            'message' => ['required', 'string', 'min:5', 'max:3000'],
            ...TurnstileToken::rules($request->ip()),
        ], $this->messages(), $this->attributes());

        $id = DB::table('contact_messages')->insertGetId([
            'name' => trim($data['name']),
            'phone' => $data['phone'] ?? null,
            'email' => isset($data['email']) ? strtolower($data['email']) : null,
            'message' => trim($data['message']),
            'is_read' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $alerts->send('contact', 'support', 'New message from :name', ['name' => Str::limit(trim($data['name']), 60)], url("/admin/modules/contact/{$id}/edit"));

        return back()->with('success', __('ui.service.contact_sent'));
    }

    public function repairs(Request $request, string $locale): Response
    {
        $user = $request->user();

        return Inertia::render('storefront/repairs', [
            'deviceTypes' => self::DEVICE_TYPES,
            'branches' => array_map(fn (array $branch): array => ['id' => $branch['id'], 'name' => $branch['name']], $this->branches($locale)),
            'requests' => $user === null ? [] : $this->repairRequests($user->id),
            'submitted' => $request->session()->get('repair_reference'),
            'turnstileSiteKey' => TurnstileToken::siteKey(),
            'maxPhotos' => self::MAX_REPAIR_PHOTOS,
            'seo' => StorefrontSeo::servicePage($locale, 'storefront.repairs', __('ui.service.repairs_title'), __('ui.service.repairs_intro')),
        ]);
    }

    public function storeRepair(Request $request, string $locale, ImageUploads $uploads, StaffAlerts $alerts): RedirectResponse
    {
        $data = $request->validate([
            'device_type' => ['required', Rule::in(self::DEVICE_TYPES)],
            'device_model' => ['nullable', 'string', 'max:120'],
            'issue' => ['required', 'string', 'min:5', 'max:2000'],
            'contact_phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'photos' => ['nullable', 'array', 'max:'.self::MAX_REPAIR_PHOTOS],
            'photos.*' => ImageUploads::rules(),
            ...TurnstileToken::rules($request->ip()),
        ], $this->messages(), $this->attributes());

        $id = DB::transaction(function () use ($data, $request, $uploads): int {
            $photos = collect($request->file('photos', []))
                ->filter(fn (mixed $file): bool => $file instanceof UploadedFile)
                ->map(fn (UploadedFile $file, int $index): string => Storage::disk(ImageUploads::DISK)
                    ->url($uploads->store($file, 'uploads/maintenance_requests', self::REPAIR_PHOTO_SIDE, "photos.{$index}")))
                ->values()
                ->all();

            return (int) DB::table('maintenance_requests')->insertGetId([
                'user_id' => $request->user()?->id,
                // In the store's main language, which staff read, whatever the customer's.
                'device_type' => __('ui.service.device_'.$data['device_type'], [], 'ar'),
                'device_model' => $data['device_model'] ?? null,
                'issue' => trim($data['issue']),
                'image_urls' => $photos === [] ? null : json_encode($photos),
                'branch_id' => $data['branch_id'] ?? null,
                'contact_phone' => $data['contact_phone'],
                'status' => 'received',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $alerts->send('repair', 'support', 'New repair request #:id: :device', [
            'id' => $id,
            'device' => Str::limit(trim(__('ui.service.device_'.$data['device_type'], [], 'ar').' '.($data['device_model'] ?? '')), 60),
        ], url("/admin/modules/maintenance/{$id}/edit"));

        return back()->with('repair_reference', $id);
    }

    /**
     * Support tickets are for signed-in customers, from their account page,
     * where they also follow the answer's status.
     */
    public function storeTicket(Request $request, string $locale, StaffAlerts $alerts): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:5', 'max:3000'],
        ], [], $this->attributes());

        $id = DB::table('support_tickets')->insertGetId([
            'user_id' => $request->user()->id,
            'subject' => trim($data['subject']),
            'message' => trim($data['message']),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $alerts->send('ticket', 'support', 'New support ticket: :subject', ['subject' => Str::limit(trim($data['subject']), 60)], url("/admin/modules/support/{$id}/edit"));

        return back()->with('success', __('ui.service.ticket_sent'));
    }

    public function simPlans(string $locale): Response
    {
        return Inertia::render('storefront/sim-plans', [
            'plans' => DB::table('sim_plans')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('monthly_price')
                ->get()
                ->map(fn (object $plan): array => [
                    'id' => (int) $plan->id,
                    'carrier' => $plan->carrier,
                    'name' => ProductCardData::localized($plan->name, $locale) ?? $plan->carrier,
                    'data_gb' => $plan->data_gb === null ? null : (float) $plan->data_gb,
                    'minutes' => $plan->minutes === null ? null : (int) $plan->minutes,
                    'sms' => $plan->sms === null ? null : (int) $plan->sms,
                    'monthly_price' => (string) $plan->monthly_price,
                    'contract_months' => (int) $plan->contract_months,
                    'features' => array_values(array_filter((array) json_decode((string) $plan->features, true), 'is_string')),
                ])
                ->values()
                ->all(),
            'whatsapp' => StorefrontFooter::for($locale)['whatsapp'],
            'seo' => StorefrontSeo::servicePage($locale, 'storefront.sim-plans', __('ui.service.sim_title'), __('ui.service.sim_intro')),
        ]);
    }

    /**
     * The signed-in customer's repair requests, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function repairRequests(int $userId): array
    {
        return array_values(DB::table('maintenance_requests')
            ->where('user_id', $userId)
            ->latest('id')
            ->limit(10)
            ->get(['id', 'device_type', 'device_model', 'status', 'estimated_cost', 'created_at'])
            ->map(fn (object $request): array => [
                'id' => (int) $request->id,
                'device' => trim($request->device_type.' '.($request->device_model ?? '')),
                'status' => (string) $request->status,
                // The same on every database: MySQL returns 250.0000, SQLite 250.
                'estimated_cost' => $request->estimated_cost === null ? null : number_format((float) $request->estimated_cost, 2, '.', ''),
                'created_at' => $request->created_at === null ? null : date(DATE_ATOM, (int) strtotime((string) $request->created_at)),
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function tickets(int $userId): array
    {
        return array_values(DB::table('support_tickets')
            ->where('user_id', $userId)
            ->latest('id')
            ->limit(10)
            ->get(['id', 'subject', 'status', 'created_at'])
            ->map(fn (object $ticket): array => [
                'id' => (int) $ticket->id,
                'subject' => (string) $ticket->subject,
                'status' => (string) $ticket->status,
                'created_at' => $ticket->created_at === null ? null : date(DATE_ATOM, (int) strtotime((string) $ticket->created_at)),
            ])
            ->all());
    }

    /**
     * Active branches with what a visitor needs: address, phone, hours and a
     * map link (from the coordinates, else from the address).
     *
     * @return list<array<string, mixed>>
     */
    private function branches(string $locale): array
    {
        return array_values(DB::table('branches')
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(function (object $branch) use ($locale): array {
                $address = ProductCardData::localized($branch->address, $locale);
                $hours = json_decode((string) $branch->working_hours, true);

                return [
                    'id' => (int) $branch->id,
                    'name' => ProductCardData::localized($branch->name, $locale),
                    'address' => $address,
                    'phone' => $branch->phone,
                    'allows_pickup' => (bool) ($branch->allows_pickup ?? false),
                    'hours' => is_array($hours) && $hours !== [] ? $hours : null,
                    'map_url' => match (true) {
                        // Six decimals (about 10 cm), the same from MySQL and SQLite.
                        $branch->lat !== null && $branch->lng !== null => 'https://www.google.com/maps/search/?api=1&query='
                            .number_format((float) $branch->lat, 6, '.', '').','.number_format((float) $branch->lng, 6, '.', ''),
                        filled($address) => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode((string) $address),
                        default => null,
                    },
                ];
            })
            ->filter(fn (array $branch): bool => $branch['name'] !== null)
            ->all());
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'turnstile_token.required' => __('ui.checkout.verification_required'),
            'phone.regex' => __('ui.service.phone_invalid'),
            'contact_phone.regex' => __('ui.service.phone_invalid'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'name' => __('ui.service.name'),
            'phone' => __('ui.service.phone'),
            'contact_phone' => __('ui.service.phone'),
            'email' => __('ui.service.email'),
            'message' => __('ui.service.message'),
            'device_type' => __('ui.service.device_type'),
            'device_model' => __('ui.service.device_model'),
            'issue' => __('ui.service.issue'),
            'branch_id' => __('ui.service.branch'),
            'photos' => __('ui.service.photos'),
            'photos.*' => __('ui.service.photos'),
            'subject' => __('ui.service.subject'),
        ];
    }
}
