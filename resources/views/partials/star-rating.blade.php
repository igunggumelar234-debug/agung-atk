{{-- Rating bintang: $rating (float/int, skala 1-5). Bintang penuh memakai floor. --}}
@php($fullStars = (int) floor((float) $rating))
@for($i = 1; $i <= 5; $i++)
    <i class="bi {{ $i <= $fullStars ? 'bi-star-fill' : 'bi-star' }} text-warning {{ $i <= $fullStars ? '' : 'opacity-25' }}" aria-hidden="true"></i>
@endfor
