<?php

namespace App\Resources;

use App\ReportFormatter;
use Illuminate\Http\Resources\Json\Resource;

class ScanResource extends Resource
{
    public function toArray($request)
    {
        return [
            'type' => 'scans',
            'id' => $this->resource->uid,
            'attributes' => [
                'hidden' => $this->resource->hidden,
                'report' => (new ReportFormatter($this->resource->report))->toArray(),
                'scanned_at' =>  optional($this->resource->scanned_at)->toW3cString(),
            ],
            'relationships' => [
                'website' => [
                    'data' => new WebsiteResource($this->resource->website),
                ],
            ],
        ];
    }
}
