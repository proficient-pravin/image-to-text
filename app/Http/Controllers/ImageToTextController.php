<?php

namespace App\Http\Controllers;

use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Illuminate\Http\Request;
use App\Http\Requests\ImageToTextRequest;
use Log;

class ImageToTextController extends Controller
{
    /**
     * @OA\Post(
     * path="/api/get-vehicle-container",
     * operationId="getVehicleContainerNumber",
     * tags={"ImageToText"},
     * summary="This will return vehicle and container number",
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *            mediaType="multipart/form-data",
     *            @OA\Schema(
     *               type="object",
     *               required={"image","response_type"},
     *               @OA\Property(property="image", type="file"),
     *               @OA\Property(property="response_type", type="text",enum={"container", "vehicle"})
     *            ),
     *        ),
     *    ),
     *      @OA\Response(
     *          response=200,
     *          description="success",
     *          @OA\JsonContent()
     *       ),
     *      @OA\Response(
     *          response=422,
     *          description="Unprocessable Entity",
     *          @OA\JsonContent()
     *       ),
     *      @OA\Response(response=500, description="Internal Server Error"),
     * )
     */
    public function getVehicleContainer(ImageToTextRequest $request)
    {
        try {
            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . storage_path('credentials.json'));

            // Check if the request contains a file
            if (!$request->hasFile('image')) {
                return response()->json(['error' => 'No image file provided'], 400);
            }

            // Get the image file from the request
            $imageFile = $request->file('image');

            // Save the image to a temporary file
            $tempFilename = tempnam(sys_get_temp_dir(), 'laravel_');
            $imageFile->move(dirname($tempFilename), basename($tempFilename));

            // Instantiate a client
            $client = new ImageAnnotatorClient();

            // Read the image file into memory
            $imageContent = file_get_contents($tempFilename);

            // Create a Vision API image object
            $image = (new \Google\Cloud\Vision\V1\Image())
                ->setContent($imageContent);

            // Perform text detection
            $response = $client->textDetection($image);

            // Get the text annotations from the response
            $textAnnotations = $response->getTextAnnotations();

            // Extract text content from each annotation
            $detectedTextList = array_map(function ($annotation) {
                return $annotation->getDescription();
            }, iterator_to_array($textAnnotations));

            $detectedText = implode(' ', $detectedTextList);

            if($request->response_type === 'vehicle'){
                $vehicleNumber = $this->vehicleNumber($detectedText);
                return response()->json([
                    'vehicle_number' => $vehicleNumber,
                    'detected_text' => $detectedText
                ], 200);
            }
            
            list($containerCode, $containerNumber) = $this->containerNumbers($detectedText);
            return response()->json([
                'container_number' => str_replace(" ","",$containerCode.$containerNumber),
                'detected_text' => $detectedText
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error: ' . $e->getMessage());
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }

    private function containerNumbers($inputString)
    {
        $inputString = str_replace(['STOP', 'SIGNAL', 'TARE', 'MAX', 'NET', 'GROSS'], '', $inputString);

        preg_match('/\b([A-Z]{4})\b/', $inputString, $containerCodeMatch);
        $containerCode = isset($containerCodeMatch[1]) ? $containerCodeMatch[1] : null;
        // preg_match('/\b(\d{6})\b/', $inputString, $containerNumberMatch);
        preg_match('/\b(\d{6} \d)\b/', $inputString, $containerNumberMatch);

        $containerNumber = isset($containerNumberMatch[1]) ? $containerNumberMatch[1] : null;

        if ($containerNumber === null && $containerCode !== null) {
            preg_match('/\b' . preg_quote($containerCode) . '\s+(\d+)\b/', $inputString, $eghuMatch);
            $containerNumber = isset($eghuMatch[1]) ? $eghuMatch[1] : null;
        }

        return [$containerCode, $containerNumber];
    }

    private function vehicleNumber($inputString)
    {
        $inputString = str_replace(['STOP', 'SIGNAL', 'TARE', 'MAX', 'NET', 'GROSS'], '', $inputString);
        preg_match('/\b(\w+\.\s*\w+)\b/', $inputString, $vehicleNumberMatch);
        $vehicleNumber = isset($vehicleNumberMatch[1]) ? $vehicleNumberMatch[1] : null;
        return str_replace([' ',"\n", '.'], '', $vehicleNumber);
    }
}
