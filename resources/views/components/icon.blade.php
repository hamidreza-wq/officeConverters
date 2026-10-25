@props(['name'])
<svg {{ $attributes->merge(['viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.7', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'aria-hidden' => 'true']) }}>
@switch($name)
    @case('layers')<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/>@break
    @case('upload')<path d="M12 16V4m-4 4 4-4 4 4M4 16v4h16v-4"/>@break
    @case('shield')<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>@break
    @case('file')<path d="M14 2H5v20h14V7l-5-5Z"/><path d="M14 2v6h5M8 13h8M8 17h5"/>@break
    @case('grid')<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/>@break
    @case('arrow')<path d="M4 12h16m-6-6 6 6-6 6"/>@break
    @case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
    @case('check')<path d="m5 12 4 4L19 6"/>@break
    @case('search')<circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/>@break
    @case('code')<path d="m8 6-6 6 6 6m8-12 6 6-6 6m-3-14-2 16"/>@break
    @case('image')<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1"/><path d="m3 17 6-5 4 3 4-6 4 4"/>@break
    @case('download')<path d="M12 3v12m-4-4 4 4 4-4M4 17v4h16v-4"/>@break
@endswitch
</svg>
