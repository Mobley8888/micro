<?php

namespace App\Services\Commercial;

use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function create(array $attributes): Product
    {
        return DB::transaction(function () use ($attributes): Product {
            $attributes['company_id'] = $this->currentCompanyId();
            $attributes['is_active'] ??= true;

            return Product::create($attributes);
        });
    }

    public function update(Product $product, array $attributes): Product
    {
        $this->ensureCompanyOwnsProduct($product);

        return DB::transaction(function () use ($product, $attributes): Product {
            $product->update($attributes);

            return $product->refresh();
        });
    }

    public function archive(Product $product): void
    {
        $this->ensureCompanyOwnsProduct($product);
        DB::transaction(fn (): ?bool => $product->delete());
    }

    public function restore(Product $product): Product
    {
        $this->ensureCompanyOwnsProduct($product);

        return DB::transaction(function () use ($product): Product {
            $product->restore();

            return $product->refresh();
        });
    }

    public function activate(Product $product): Product
    {
        return $this->setActive($product, true);
    }

    public function deactivate(Product $product): Product
    {
        return $this->setActive($product, false);
    }

    private function setActive(Product $product, bool $active): Product
    {
        $this->ensureCompanyOwnsProduct($product);

        return DB::transaction(function () use ($product, $active): Product {
            $product->update(['is_active' => $active]);

            return $product->refresh();
        });
    }

    private function ensureCompanyOwnsProduct(Product $product): void
    {
        abort_unless($product->company_id === $this->currentCompanyId(), 404);
    }

    private function currentCompanyId(): string
    {
        $user = Auth::user();
        abort_unless($user !== null, 401);
        abort_unless($user->company_id !== null, 404);

        return (string) $user->company_id;
    }
}
