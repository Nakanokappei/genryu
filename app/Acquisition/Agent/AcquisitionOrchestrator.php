<?php

namespace App\Acquisition\Agent;

/**
 * Port for the judgement half of acquisition (ADR-0001). An implementation
 * decides where to look and which tools to call; it never touches storage
 * or the network except through the AgentToolBridge.
 */
interface AcquisitionOrchestrator
{
    /**
     * Explore a source and, ideally, store a Source Profile candidate.
     */
    public function discover(DiscoveryTask $task): DiscoveryOutcome;
}
