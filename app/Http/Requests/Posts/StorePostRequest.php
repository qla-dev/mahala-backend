<?php

namespace App\Http\Requests\Posts;

use App\DataTransferObjects\Posts\StorePostData;
use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:250'],
            'mahala_id' => ['nullable', 'integer'],
            'channel_id' => ['nullable', 'integer'],
            'color_class' => ['required', 'string', 'max:50'],
            'is_anonymous' => ['required', 'boolean'],
            'is_image' => ['required', 'boolean'],
            'image_url' => ['nullable', 'url'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ];
    }

    public function toDto(): StorePostData
    {
        $user = $this->user();

        return new StorePostData(
            userId: $user->id,
            authorUsername: $user->username,
            content: $this->string('content')->toString(),
            mahalaId: $this->integer('mahala_id') ?: null,
            channelId: $this->integer('channel_id') ?: null,
            colorClass: $this->string('color_class')->toString(),
            isAnonymous: $this->boolean('is_anonymous'),
            isImage: $this->boolean('is_image'),
            imageUrl: $this->filled('image_url') ? $this->string('image_url')->toString() : null,
            latitude: $this->filled('latitude') ? (float) $this->input('latitude') : null,
            longitude: $this->filled('longitude') ? (float) $this->input('longitude') : null,
        );
    }
}
