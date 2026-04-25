<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class FaceVerificationController extends Controller
{
    private string $mlServiceUrl = 'http://127.0.0.1:8001';

    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => 'required|string|exists:students,student_id',
            'image'      => 'required|file|mimes:jpeg,jpg,png|max:2048',
        ]);

        try {
            $response = Http::timeout(30)
                ->attach('image', file_get_contents($request->file('image')->path()), 'frame.jpg')
                ->post("{$this->mlServiceUrl}/verify/", [
                    'user_id' => $request->student_id,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return response()->json([
                    'ok'         => true,
                    'verified'   => (bool) $data['verified'],
                    'distance'   => $data['distance'],
                    'confidence' => $data['confidence'],
                    'message'    => $data['message'],
                ]);
            }

            if ($response->status() === 404) {
                return response()->json([
                    'ok'       => false,
                    'verified' => false,
                    'message'  => 'Face not registered in ML service. Please re-register.',
                ]);
            }

            return response()->json([
                'ok'       => false,
                'verified' => false,
                'message'  => 'ML service error: ' . $response->body(),
            ], 500);

        } catch (\Exception $e) {
           \Illuminate\Support\Facades\Log::error('ML verify failed: ' . $e->getMessage());
            return response()->json([
                'ok'       => false,
                'verified' => false,
                'message'  => 'ML service unreachable: ' . $e->getMessage(),
            ], 503);
        }
    }

    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => 'required|string|exists:students,student_id',
            'image1'     => 'required|file|mimes:jpeg,jpg,png|max:2048',
            'image2'     => 'required|file|mimes:jpeg,jpg,png|max:2048',
        ]);

        try {
            $response = Http::timeout(30)
                ->attach('image1', file_get_contents($request->file('image1')->path()), 'img1.jpg')
                ->attach('image2', file_get_contents($request->file('image2')->path()), 'img2.jpg')
                ->post("{$this->mlServiceUrl}/register/", [
                    'user_id' => $request->student_id,
                ]);

            if ($response->successful()) {
                return response()->json(['ok' => true, 'message' => 'Face registered in ML service.']);
            }

            return response()->json([
                'ok'      => false,
                'message' => 'ML registration failed: ' . $response->body(),
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'ML service unreachable: ' . $e->getMessage(),
            ], 503);
        }
    }
}
