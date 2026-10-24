<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaystackService
{
    /**
     * Create a new class instance.
     */
   private function secretKey(): string
   {
    $key = config('services.paystack.secret_key');

    if (!$key) {
        throw new RuntimeException('Paystack secret key is not configured.');
    }

    return $key;
   }

   public function initializeTransaction(string $email, int $amountKobo, string $reference, array $metadata = []): array 
   {
    $response = Http::withToken($this->secretKey())
    ->acceptJson()
    ->post('https://api.paystack.co/transaction/initialize', [
        'email' => $email,
        'amount' => $amountKobo,
        'reference' => $reference,
        'metadata' => $metadata,
        
        ]);

        $response->throw();

        $body = $response->json();

        if (!($body['status'] ?? false)) {
            throw new RuntimeException('Paystack could not initialize the transaction');
        }

        return $body['data'];
   }

   public function verifyTransaction(string $reference): array
   
   {
    $response = Http::withToken($this->secretKey())
    ->acceptJson()
    ->get('https://api.paystack.co/transaction/verify/' . rawurldecode($reference));

    $response->throw();

    $body = $response->json();

    if (!($body['status'] ?? false)) {
        throw new RuntimeException('Paystack could not verify the transaction.');
    }

    return $body['data'];
   }
}
