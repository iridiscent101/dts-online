<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Orchid\Access\Impersonation;
use Orchid\Support\Facades\Toast;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class UserSignatureController extends Controller
{
    public function show(Request $request): BinaryFileResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->signature_path, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($user->signature_path), 404);

        return response()->file($disk->path($user->signature_path), [
            'Content-Type' => $disk->mimeType($user->signature_path),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        abort_if(Impersonation::isSwitch(), 403);

        $request->validate([
            'signature' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:256', Rule::dimensions()->maxWidth(1200)->maxHeight(600)],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $disk = Storage::disk('local');
        $oldPath = $user->signature_path;
        $newPath = $request->file('signature')->store('staff-signatures', 'local');
        abort_unless($newPath, 500, 'Could not save the signature image.');

        try {
            $user->signature_path = $newPath;
            $user->save();
        } catch (Throwable $exception) {
            $disk->delete($newPath);

            throw $exception;
        }

        if ($oldPath) {
            $disk->delete($oldPath);
        }

        if ($request->expectsJson()) {
            return response()->json(['signature_url' => route('platform.profile.signature.show')]);
        }

        Toast::success('Signature updated.');

        return redirect()->route('platform.profile');
    }
}
