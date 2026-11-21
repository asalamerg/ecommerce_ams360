<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * phone_digits was added to the 000019 migration after some databases had
 * already run it, so they never got the column. This adds it where it is
 * missing and fills it; where 000019 created it, nothing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'phone_digits')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('phone_digits', 20)->nullable()->index();
        });

        DB::table('orders')->orderBy('id')->each(fn (object $order) => DB::table('orders')
            ->where('id', $order->id)
            ->update(['phone_digits' => preg_replace('/\D/', '', (string) $order->phone)]));
    }

    public function down(): void
    {
        // Owned by 000019 where that migration created it; nothing to undo here.
    }
};
