@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- Logo con fondo incluido: se ve igual aunque el cliente de correo ignore los colores de fondo. --}}
<img src="{{ asset('img/marca/logo-mozistock-correo.png') }}" class="logo" width="243" height="64" alt="{{ trim(strip_tags($slot)) ?: 'MoziStock' }}">
</a>
</td>
</tr>
