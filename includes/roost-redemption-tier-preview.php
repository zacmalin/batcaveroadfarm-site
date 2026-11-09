<?php
// Pure Roost Rewards redemption preview. No point deductions or reservations.
// Tier benefits and monetary values must be configured and approved separately.
function bcrf_roost_redemption_tier_preview(int $balancePoints): array {
    $tiers=[250,500,1000,2000];
    $result=[];
    foreach ($tiers as $points) {
        $result[]=[
            'points'=>$points,
            'available'=>$balancePoints >= $points,
            'points_needed'=>max(0,$points-$balancePoints),
        ];
    }
    return $result;
}
