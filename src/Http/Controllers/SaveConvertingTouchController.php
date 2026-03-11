<?php

namespace SwooInc\Attribution\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use SwooInc\Attribution\AttributionRules;
use SwooInc\Attribution\AttributionService;

class SaveConvertingTouchController extends Controller
{
    /**
     * Update the converting touch for the authenticated user.
     *
     * Called from the frontend after the first subscription succeeds.
     * Silently skips if no provenance record exists for this user.
     */
    public function __invoke(Request $request): Response
    {
        $request->validate(AttributionRules::rules());

        $attribution = $request->input('attribution', []);
        $service = app(AttributionService::class);
        $userId = $request->user()->id;

        if (is_array($attribution) && !empty($attribution)) {
            $service->updateConvertingForUser($userId, $attribution);
        } else {
            // No new attribution (e.g. user signed up and subscribed in the
            // same visit — localStorage was cleared after signup). Fall back
            // to copying the last touch already stored in the record.
            $service->updateConvertingFromLastTouch($userId);
        }

        return response()->noContent();
    }
}
