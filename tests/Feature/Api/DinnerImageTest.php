<?php

use App\Actions\DinnerImages\PruneDinnerImages;
use App\Models\AiRequest;
use App\Models\Dinner;
use App\Models\DinnerImage;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

beforeEach(function () {
    Storage::fake('public');
    config(['filesystems.media_disk' => 'public', 'assistance.enabled' => true, 'ai.providers.openrouter.key' => 'test-server-key']);
    [$this->user, $this->household] = ownerWithHousehold();
    Passport::actingAs($this->user);
});

function jpegOf(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 90, 40));
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

function imageResponse(float $cost = 0.012): array
{
    return [
        'data' => [['b64_json' => base64_encode(jpegOf(1024, 1024)), 'media_type' => 'image/jpeg']],
        'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 4096, 'cost' => $cost],
    ];
}

it('stores an upload as square webp variants with a thumbhash', function () {
    $upload = UploadedFile::fake()->createWithContent('dinner.jpg', jpegOf(1600, 1200));

    $response = $this->post('/api/v1/dinner-images', ['image' => $upload], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['path', 'thumbhash', 'url']]);

    $path = $response->json('data.path');
    expect($path)->toMatch(DinnerImage::PATH_PATTERN);
    foreach (DinnerImage::SIZES as $size) {
        Storage::disk('public')->assertExists("{$path}/{$size}.webp");
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get("{$path}/{$size}.webp"));
        expect([$width, $height])->toBe([$size, $size]);
    }
    $this->assertDatabaseHas('dinner_images', [
        'path' => $path, 'household_id' => $this->household->id, 'user_id' => $this->user->id, 'source' => 'photo',
    ]);
});

it('does not publish the photo\'s location or other metadata', function () {
    $photo = new Imagick;
    $photo->newImage(1600, 1200, new ImagickPixel('orange'));
    $photo->setImageFormat('jpeg');
    $photo->setImageProfile('xmp', '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLatitude="59,54.123N"/></rdf:RDF></x:xmpmeta>');
    $upload = UploadedFile::fake()->createWithContent('dinner.jpg', $photo->getImageBlob());

    $path = $this->post('/api/v1/dinner-images', ['image' => $upload], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.path');

    foreach (DinnerImage::SIZES as $size) {
        expect(Storage::disk('public')->get("{$path}/{$size}.webp"))->not->toContain('GPSLatitude');
    }
})->skip(! extension_loaded('imagick'), 'GD never keeps metadata.');

it('rejects files that are not usable images', function (UploadedFile $file) {
    $this->post('/api/v1/dinner-images', ['image' => $file], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('image');
    $this->assertDatabaseCount('dinner_images', 0);
})->with([
    'text' => fn () => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
    'tiny' => fn () => UploadedFile::fake()->createWithContent('tiny.jpg', jpegOf(20, 20)),
]);

it('generates an AI picture through OpenRouter and records its cost', function () {
    Http::fake(['openrouter.ai/api/v1/images' => Http::response(imageResponse(0.012))]);

    $this->postJson('/api/v1/dinner-images/generate', [
        'name' => 'Taco Friday', 'ingredients' => ['Beef', 'Tortillas'], 'category' => 'meat',
    ])->assertCreated()->assertJsonStructure(['data' => ['path', 'thumbhash', 'url']]);

    Http::assertSent(fn (ClientRequest $request) => $request['model'] === config('assistance.images.model')
        && str_contains($request['prompt'], 'Taco Friday')
        && str_contains($request['prompt'], 'beef, tortillas')
        && $request['provider'] === ['data_collection' => 'deny']
        && $request->hasHeader('Authorization', 'Bearer test-server-key'));
    expect(DinnerImage::query()->sole()->source)->toBe('ai');
    $request = AiRequest::query()->sole();
    expect($request->feature)->toBe('images')->and($request->status)->toBe('ok')->and((float) $request->cost)->toBe(0.012);
});

it('stops generating once the daily budget is spent', function () {
    config(['assistance.images.daily_budget' => 0.02]);
    AiRequest::factory()->create(['feature' => 'images', 'status' => 'ok', 'cost' => 0.02, 'created_at' => now()]);
    Http::fake();

    $this->postJson('/api/v1/dinner-images/generate', ['name' => 'Soup', 'ingredients' => []])
        ->assertStatus(429)->assertJsonPath('code', 'daily_limit')->assertHeader('Retry-After');
    Http::assertNothingSent();
});

it('enforces the per-user daily image limit', function () {
    config(['assistance.limits.images.user' => 1]);
    Http::fake(['openrouter.ai/api/v1/images' => Http::response(imageResponse())]);

    $this->postJson('/api/v1/dinner-images/generate', ['name' => 'Soup', 'ingredients' => []])->assertCreated();
    $this->postJson('/api/v1/dinner-images/generate', ['name' => 'Soup', 'ingredients' => []])
        ->assertStatus(429)->assertJsonPath('code', 'daily_limit');
});

it('reports a provider failure without storing anything', function () {
    Http::fake(['openrouter.ai/api/v1/images' => Http::response(['error' => ['message' => 'down']], 500)]);

    $this->postJson('/api/v1/dinner-images/generate', ['name' => 'Soup', 'ingredients' => []])
        ->assertStatus(503)->assertJsonPath('code', 'unavailable');
    $this->assertDatabaseCount('dinner_images', 0);
    expect(AiRequest::query()->sole()->status)->toBe('failed');
});

it('requires a verified email to generate', function () {
    $this->user->forceFill(['email_verified_at' => null])->save();
    Http::fake();

    $this->postJson('/api/v1/dinner-images/generate', ['name' => 'Soup', 'ingredients' => []])
        ->assertForbidden()->assertJsonPath('code', 'verification_required');
    Http::assertNothingSent();
});

it('syncs a dinner picture and emoji from the household uploads only', function () {
    $own = DinnerImage::query()->create(['household_id' => $this->household->id, 'user_id' => $this->user->id,
        'path' => 'dinner-images/'.Str::random(32), 'source' => 'photo', 'thumbhash' => 'abc=']);
    [, $otherHousehold] = ownerWithHousehold();
    $foreign = DinnerImage::query()->create(['household_id' => $otherHousehold->id, 'user_id' => null,
        'path' => 'dinner-images/'.Str::random(32), 'source' => 'photo', 'thumbhash' => 'abc=']);
    Passport::actingAs($this->user);
    $row = fn (array $attributes) => ['id' => (string) Str::uuid(), 'default_servings' => 2, 'notes' => null,
        'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString(), 'deleted_at' => null, ...$attributes];
    $withImage = $row(['name' => 'Pizza', 'image_path' => $own->path, 'image_thumbhash' => 'abc=']);
    $withEmoji = $row(['name' => 'Soup', 'emoji' => '🍲']);
    $stolen = $row(['name' => 'Stolen', 'image_path' => $foreign->path, 'image_thumbhash' => 'abc=']);

    $response = $this->postJson('/api/v1/sync', [
        'cursor' => null, 'household_id' => $this->household->id, 'changes' => ['dinners' => [$withImage, $withEmoji, $stolen]],
    ])->assertOk();

    expect($response->json('rejected.dinners.0.id'))->toBe($stolen['id']);
    expect(Dinner::query()->where('uuid', $withImage['id'])->value('image_path'))->toBe($own->path);
    expect(Dinner::query()->where('uuid', $withEmoji['id'])->value('emoji'))->toBe('🍲');
    $pulled = collect($response->json('changes.dinners'))->keyBy('id');
    expect($pulled[$withImage['id']]['image_thumbhash'])->toBe('abc=')
        ->and($pulled[$withEmoji['id']]['emoji'])->toBe('🍲');
});

it('prunes pictures no dinner uses after the grace period', function () {
    $make = function (string $age) {
        $path = 'dinner-images/'.Str::random(32);
        foreach (DinnerImage::files($path) as $file) {
            Storage::disk('public')->put($file, 'x');
        }
        $image = DinnerImage::query()->create(['household_id' => $this->household->id, 'user_id' => $this->user->id,
            'path' => $path, 'source' => 'photo', 'thumbhash' => 'abc=']);
        $image->forceFill(['created_at' => now()->sub($age)])->save();

        return $image;
    };
    $used = $make('2 days');
    $orphan = $make('2 days');
    $fresh = $make('1 hour');
    $deletedLongAgo = $make('60 days');
    Dinner::factory()->for($this->household)->create(['image_path' => $used->path]);
    $gone = Dinner::factory()->for($this->household)->create(['image_path' => $deletedLongAgo->path]);
    $gone->forceFill(['deleted_at' => now()->subDays(40)])->save();

    expect(app(PruneDinnerImages::class)->handle())->toBe(2);

    expect(DinnerImage::query()->pluck('path')->all())->toEqualCanonicalizing([$used->path, $fresh->path]);
    Storage::disk('public')->assertExists($used->path.'/160.webp');
    Storage::disk('public')->assertMissing($orphan->path.'/1024.webp');
    Storage::disk('public')->assertMissing($deletedLongAgo->path.'/480.webp');
});

it('erases picture fields with the rest of a deleted author\'s dinner content', function () {
    expect((new Dinner)->contentErasureDefaults())->toMatchArray(['emoji' => null, 'image_path' => null, 'image_thumbhash' => null]);
});
