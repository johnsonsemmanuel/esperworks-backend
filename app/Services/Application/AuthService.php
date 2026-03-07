<?php

namespace App\Services\Application;

use App\Models\User;
use App\Models\Business;
use App\Models\Client;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\Interfaces\BusinessRepositoryInterface;
use App\Services\Infrastructure\Email\EmailService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private BusinessRepositoryInterface $businessRepository,
        private EmailService $emailService
    ) {}

    public function login(array $credentials): array
    {
        $user = $this->userRepository->findByEmail($credentials['email']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new \InvalidArgumentException('Invalid credentials');
        }

        $token = $user->createToken('auth-token')->plainTextToken;
        $businesses = $this->businessRepository->findByUserId($user->id);

        return [
            'user' => $user,
            'token' => $token,
            'businesses' => $businesses,
        ];
    }

    public function register(array $data): array
    {
        $user = $this->userRepository->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'business_owner',
        ]);

        $business = $this->businessRepository->create([
            'name' => $data['business_name'],
            'user_id' => $user->id,
            'email' => $data['email'],
            'plan' => 'free',
            'currency' => $data['currency'] ?? 'GHS',
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
            'business' => $business,
        ];
    }

    public function clientLogin(array $credentials): array
    {
        $user = $this->userRepository->findByEmail($credentials['email']);
        
        if (!$user || $user->role !== 'client' || !Hash::check($credentials['password'], $user->password)) {
            throw new \InvalidArgumentException('Invalid client credentials');
        }

        $token = $user->createToken('client-token', ['client'])->plainTextToken;
        $clientProfiles = $user->clientProfiles()->with('business:id,name,logo')->get();

        return [
            'user' => $user,
            'token' => $token,
            'client_profiles' => $clientProfiles,
            'must_change_password' => $user->must_change_password ?? false,
        ];
    }

    public function logout(User $user): void
    {
        $user->tokens()->delete();
    }

    public function refreshToken(User $user): array
    {
        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return ['token' => $token];
    }

    public function getCurrentUser(User $user): User
    {
        return $user->load(['businesses', 'clientProfiles']);
    }
}
