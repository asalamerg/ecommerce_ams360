<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\AdjustProductStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/inventory/index', [
            'products' => Product::query()
                ->with('translations')
                ->orderBy('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Product $product) => [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->translations->pluck('name', 'locale')->get('ar')
                        ?? $product->translations->pluck('name', 'locale')->get('en')
                        ?? $product->translations->pluck('name', 'locale')->get('he'),
                    'stock_quantity' => $product->stock_quantity,
                ]),
            'movements' => StockMovement::query()
                ->with(['product.translations', 'user:id,name'])
                ->latest('created_at')
                ->paginate(20, ['*'], 'movements_page')
                ->withQueryString()
                ->through(fn (StockMovement $movement) => [
                    'id' => $movement->id,
                    'product' => $movement->product === null
                        ? __(':name (deleted)', ['name' => $movement->product_name ?? '—'])
                        : $movement->product->translations->pluck('name', 'locale')->get('ar')
                            ?? $movement->product->translations->pluck('name', 'locale')->get('en')
                            ?? $movement->product->translations->pluck('name', 'locale')->get('he'),
                    'actor' => $movement->user_id === null
                        ? __('Deleted user')
                        : $movement->user->name,
                    'direction' => $movement->direction,
                    'quantity_delta' => $movement->quantity_delta,
                    'stock_before' => $movement->stock_before,
                    'stock_after' => $movement->stock_after,
                    'reason' => $movement->reason,
                    'created_at' => $movement->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function adjust(
        AdjustStockRequest $request,
        Product $product,
        AdjustProductStock $adjustProductStock,
    ): RedirectResponse {
        $data = $request->validated();
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $adjustProductStock->execute(
            $product,
            $actor,
            $data['direction'],
            (int) $data['quantity'],
            $data['reason'],
        );

        return back()->with('success', __('Inventory updated and recorded.'));
    }
}
