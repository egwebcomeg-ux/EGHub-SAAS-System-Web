<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Exception\WriterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The free QR code generator (/qr-code-generator): a lead magnet for the marketing site. The browser builds the
 * payload and draws the styled code; this server only turns a payload into the module matrix (BaconQrCode), since
 * no JavaScript QR library ships with the site. Nothing is stored: no payload, no logo (the logo never leaves the browser).
 */
class QrToolController extends Controller
{
    /** Longest payload accepted, in characters. */
    public const MAX_LENGTH = 2000;

    public function show(): View
    {
        return view('landing.qr-tool', ['maxLength' => self::MAX_LENGTH]);
    }

    /** The module matrix of one payload: rows of 0 (light) and 1 (dark), without the quiet zone. */
    public function matrix(Request $request): JsonResponse
    {
        $data = $request->validate([
            'data' => ['required', 'string', 'max:'.self::MAX_LENGTH],
            'ecc' => ['required', 'string', 'in:L,M,Q,H'],
        ]);

        // plain ASCII keeps Bacon's compact default modes; anything else is UTF-8 (with its ECI marker)
        $encoding = mb_check_encoding($data['data'], 'ASCII') ? Encoder::DEFAULT_BYTE_MODE_ENCODING : 'UTF-8';
        try {
            $code = Encoder::encode($data['data'], ErrorCorrectionLevel::valueOf($data['ecc']), $encoding);
        } catch (WriterException) {
            return response()->json([
                'message' => __('This content is too long for one QR code. Shorten it or lower the error correction.'),
                'errors' => ['data' => [__('This content is too long for one QR code. Shorten it or lower the error correction.')]],
            ], 422);
        }

        $matrix = $code->getMatrix();
        $rows = [];
        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            $row = [];
            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $row[] = $matrix->get($x, $y) === 1 ? 1 : 0;
            }
            $rows[] = $row;
        }

        return response()->json([
            'version' => $code->getVersion()->getVersionNumber(),
            'size' => $matrix->getWidth(),
            'ecc' => $data['ecc'],
            'matrix' => $rows,
        ])->header('Cache-Control', 'no-store');
    }
}
