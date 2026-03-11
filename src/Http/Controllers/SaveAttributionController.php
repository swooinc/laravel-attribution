<?php

namespace SwooInc\Attribution\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use SwooInc\Attribution\AttributionRules;
use SwooInc\Attribution\AttributionService;

class SaveAttributionController extends Controller
{
    /**
     * Save the initial attribution record for the authenticated user.
     *
     * Called from the frontend after signup succeeds.
     * Silently skips if a record already exists for this user.
     */
    public function __invoke(Request $request): Response
    {
        $request->validate(AttributionRules::rules());

        $attribution = $request->input('attribution', []);

        if (is_array($attribution) && !empty($attribution)) {
            app(AttributionService::class)
                ->saveForUser($request->user()->id, $attribution);
        }

        return response()->noContent();
    }
}
