<?php

namespace App\Jobs;

use App\Models\MenuImport;
use App\Services\Import\Ai\AiException;
use App\Services\Import\Ai\AiProviders;
use App\Services\Import\MenuDraft;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads uploaded menu photos/PDF with the restaurant's own AI key, in the background (20-60 s).
 * Tenant context is restored by stancl's QueueTenancyBootstrapper. Uploaded files are deleted afterwards.
 */
class ProcessMenuImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $importId)
    {
    }

    public function handle(): void
    {
        $import = MenuImport::query()->find($this->importId);
        if (!$import || $import->status !== 'pending') {
            return;
        }
        $paths = json_decode($import->file, true) ?: [];
        try {
            $files = array_map(fn ($p) => [
                'mime' => str_ends_with($p, '.pdf') ? 'application/pdf' : (Storage::disk('local')->mimeType($p) ?: 'image/jpeg'),
                'data' => base64_encode((string) Storage::disk('local')->get($p)),
            ], $paths);

            $draft = MenuDraft::normalize(AiProviders::make()->extract($files));
            if (MenuDraft::itemCount($draft) === 0) {
                throw new AiException(__('No menu items were found. Try clearer photos.'));
            }
            $import->update(['status' => 'parsed', 'result' => $draft, 'error' => null]);
        } catch (AiException|\RuntimeException $e) {
            $import->update(['status' => 'failed', 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('menu import failed', ['import' => $import->id, 'error' => $e->getMessage()]);
            $import->update(['status' => 'failed', 'error' => __('Something went wrong while reading the menu. Try again.')]);
        } finally {
            Storage::disk('local')->delete($paths);
        }
    }
}
