<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\RunnerProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:create-test-runner-profile')]
#[Description('Create a test runner profile for debugging')]
class CreateTestRunnerProfile extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = User::first();
        
        if (!$user) {
            $this->error('No user found!');
            return 1;
        }

        $this->info("Creating profile for user: {$user->email}");

        try {
            // Create or update profile
            $profile = $user->runnerProfile ?? new RunnerProfile();
            
            $profile->user_id = $user->id;

            $profile->threshold_heart_rate = 165;
            $profile->max_heart_rate = 195;
            $profile->threshold_speed = 5.5; // 5:30
            
            // Calculate zones
            $profile->heart_rate_zones = $profile->calculateHeartRateZones();
            $profile->pace_zones = $profile->calculatePaceZones();
            $profile->has_completed_setup = true;
            
            $profile->save();

            $this->info("✅ Profile saved successfully!");
            $this->info("Profile ID: {$profile->id}");

            $this->info("Threshold Speed: {$profile->threshold_speed}");
            
            // Verify it was saved
            $retrieved = RunnerProfile::find($profile->id);
            if ($retrieved) {
                $this->info("✅ Verified: Profile found in database!");
                $this->info("Heart Rate Zones: " . json_encode($retrieved->heart_rate_zones));
                $this->info("Pace Zones: " . json_encode($retrieved->pace_zones));
            } else {
                $this->error("❌ Profile not found in database after save!");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }
}
