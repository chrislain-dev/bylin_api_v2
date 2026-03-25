<?php

namespace Modules\Catalogue\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,

            // Hierarchie
            'parent_id' => $this->parent_id,
            'level' => $this->level,
            'path' => $this->path,
            'full_path' => $this->full_path, // /categories/slug

            // Breadcrumbs automatique pour navigation
            'breadcrumbs' => $this->breadcrumbs ?? [],

            // Visuel
            'image' => $this->image,
            'icon' => $this->icon,
            'color' => $this->color,

            // État
            'is_active' => $this->is_active,
            'is_visible_in_menu' => $this->is_visible_in_menu,
            'is_featured' => $this->is_featured,
            'sort_order' => $this->sort_order,

            // SEO
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,

            // Stats
            'products_count' => $this->products_count,

            // Relations (chargées si demandées)
            'parent' => $this->whenLoaded('parent', fn() => new CategoryResource($this->parent)),
            'children' => CategoryResource::collection($this->whenLoaded('children')),

            // Dates
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
