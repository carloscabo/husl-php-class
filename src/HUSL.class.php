<?php

/*
  Port of HUSL Color library to PHP by Carlos Cabo.
  https://github.com/husl-colors

  The math for most of this module was taken from:

  * http://www.easyrgb.com
  * http://www.brucelindbloom.com
  * Wikipedia

  All numbers below taken from math/bounds.wxm wxMaxima file. We use 17
  digits of decimal precision to export the numbers, effectively exporting
  them as double precision IEEE 754 floats.

  "If an IEEE 754 double precision is converted to a decimal string with at
  least 17 significant digits and then converted back to double, then the
  final number must match the original"

  Source: https://en.wikipedia.org/wiki/Double-precision_floating-point_format
*/

class HUSL {

  private static $initialized = false;

  public static function initialize() {
 		return;
  }

  private static $m = array(
    'R' => array(3.2409699419045214, -1.5373831775700935, -0.49861076029300328),
    'G' => array(-0.96924363628087983, 1.8759675015077207, 0.041555057407175613),
    'B' => array(0.055630079696993609, -0.20397695888897657, 1.0569715142428786)
  );
  private static  $m_inv = [
    'X' => array(0.41239079926595948, 0.35758433938387796, 0.18048078840183429),
    'Y' => array(0.21263900587151036, 0.71516867876775593, 0.072192315360733715),
    'Z' => array(0.019330818715591851, 0.11919477979462599, 0.95053215224966058)
  ];

  private static $refU = 0.19783000664283681;
  private static $refV = 0.468319994938791;

  // CIE LUV constants
  private static $kappa = 903.2962962962963;
  private static $epsilon = 0.0088564516790356308;

  // For a given lightness, return a list of 6 lines in slope-intercept
  // form that represent the bounds in CIELUV, stepping over which will
  // push a value out of the RGB gamut
  private static function getBounds( $L ) {
    $sub1 = pow( $L + 16, 3 ) / 1560896;
    $sub2 = $sub1 > self::$epsilon ? $sub1 : $L / self::$kappa;
    $ret = array();
    $iterable = array('R', 'G', 'B');

    for ( $i = 0; $i < count($iterable); $i++ ) {
      $channel = $iterable[$i];

      $m1 = $m[$channel][0];
      $m2 = $m[$channel][1];
      $m3 = $m[$channel][2];

      $iterable1 = [0, 1];
      for ($j = 0; $j < count($iterable1); $j++) {

        $t = $iterable1[$j];
        $top1 = (284517 * $m1 - 94839 * $m3) * $sub2;
        $top2 = (838422 * $m3 + 769860 * $m2 + 731718 * $m1) * $L * $sub2 - 769860 * $t * $L;
        $bottom = (632260 * $m3 - 126452 * $m2) * $sub2 + 126452 * $t;

        $ret[] = array( $top1 / $bottom, $top2 / $bottom );
      }
    }
    return $ret;
  }

  private static function intersectLineLine( $line1, $line2 ) {
    return ( $line1[1] - $line2[1]) / ($line2[0] - $line1[0] );
  }

  private static function distanceFromPole( $point ) {
    return sqrt( pow( $point[0], 2 ) + pow( $point[1], 2 ) );
  }

  private static function lengthOfRayUntilIntersect( $theta, $line ) {
    // theta  -- angle of ray starting at (0, 0)
    // m, b   -- slope and intercept of line
    // x1, y1 -- coordinates of intersection
    // len    -- length of ray until it intersects with line
    //
    // b + m * x1        = y1
    // len              >= 0
    // len * cos(theta)  = x1
    // len * sin(theta)  = y1
    //
    //
    // b + m * (len * cos(theta)) = len * sin(theta)
    // b = len * sin(hrad) - m * len * cos(theta)
    // b = len * (sin(hrad) - m * cos(hrad))
    // len = b / (sin(hrad) - m * cos(hrad))
    //
    $m1 = $line[0];
    $b1 = $line[1];

    $len = $b1 / ( sin( $theta ) - $m1 * cos( $theta ) );
    if ( $len < 0 ) {
      return null;
    }
    return $len;
  }

  // For given lightness, returns the maximum chroma. Keeping the chroma value
  // below this number will ensure that for any hue, the color is within the RGB
  // gamut.
  private static function maxSafeChromaForL( $L ) {
    $lengths = array();
    $iterable = self::getBounds( $L );
    for ( $i = 0; $i < count($iterable); $i++) {

      // x where line intersects with perpendicular running though (0, 0)
      $m1 = $iterable[$i][0];
      $b1 = $iterable[$i][1];

      $x = self::intersectLineLine( [$m1, $b1], [-1 / $m1, 0] );
      $lengths[] = self::distanceFromPole( [$x, $b1 + $x * $m1] );
    }
    return min( lengths );
  }

  // For a given lightness and hue, return the maximum chroma that fits in
  // the RGB gamut.
  private static function maxChromaForLH( $L, $H ) {
    $hrad = $H / 360 * M_PI * 2;
    $lengths = array();
    $iterable = self::getBounds( $L );
    for ( $i = 0; $i < count($iterable); $i++) {
      $line = $iterable[$i];
      $l = self::lengthOfRayUntilIntersect( $hrad, $line );
      if ( !is_null($l) ) {
        $lengths[] = $l;
      }
    }
    return min( $lengths );
  }

  private static function dotProduct( $a, $b ) {
    $ret = 0;
    for ( $i = 0; $i < count($a); $i++) {
      $ret += $a[$i] * $b[$i];
    }
    return $ret;
  }

  // Used for rgb conversions
  private static function fromLinear( $c ) {
    if ( $c <= 0.0031308 ) {
      return 12.92 * $c;
    } else {
      return 1.055 * pow( $c, 1 / 2.4 ) - 0.055;
    }
  }

  private static function toLinear( $c ) {
    $a = 0.055;
    if ( $c > 0.04045 ) {
      return pow( ( $c + $a ) / ( 1 + $a ), 2.4 );
    } else {
      return $c / 12.92;
    }
  }

  public static function xyzToRgb ( $tuple ) {
    $R = self::fromLinear( self::dotProduct( self::$m['R'], $tuple ) );
    $G = self::fromLinear( self::dotProduct( self::$m['G'], $tuple ) );
    $B = self::fromLinear( self::dotProduct( self::$m['B'], $tuple ) );
    return array( $R, $G, $B );
  }

  public static function rgbToXyz( $tuple ) {
    $R = $tuple[0];
    $G = $tuple[1];
    $B = $tuple[2];

    $rgbl = [ self::toLinear( $R ), self::toLinear( $G ), self::toLinear( $B ) ];
    $X = self::dotProduct( self::m_inv['X'], $rgbl);
    $Y = self::dotProduct( self::m_inv['Y'], $rgbl);
    $Z = self::dotProduct( self::m_inv['Z'], $rgbl);
    return array( $X, $Y, $Z );
  }

  // http://en.wikipedia.org/wiki/CIELUV
  // In these formulas, Yn refers to the reference white point. We are using
  // illuminant D65, so Yn (see refY in Maxima file) equals 1. The formula is
  // simplified accordingly.
  private static function Y_to_L( $Y ) {
    if ( $Y <= self::$epsilon ) {
      return $Y * self::$kappa;
    } else {
      return 116 * pow( $Y, 1 / 3 ) - 16;
    }
  };
  private static function L_to_Y( $L ) {
    if ( $L <= 8 ) {
      return $L / self::$kappa;
    } else {
      return pow( ( $L + 16 ) / 116, 3 );
    }
  }

  public static function xyzToLuv( $tuple ) {
    $X = $tuple[0];
    $Y = $tuple[1];
    $Z = $tuple[2];
    // Black will create a divide-by-zero error in
    // the following two lines

    if ( $Y === 0 ) {
      return array( 0, 0, 0 );
    }
    $L = self::Y_to_L( $Y );
    $varU = 4 * $X / ( $X + 15 * $Y + 3 * $Z );
    $varV = 9 * $Y / ( $X + 15 * $Y + 3 * $Z );
    $U = 13 * $L * ( $varU - $refU );
    $V = 13 * $L * ( $varV - $refV );
    return array( $L, $U, $V );
  }

  public static function luvToXyz( $tuple ) {
    $L = $tuple[0];
    $U = $tuple[1];
    $V = $tuple[2];
    // Black will create a divide-by-zero error

    if ( $L === 0 ) {
      return array( 0, 0, 0 );
    }
    $varU = $U / ( 13 * $L ) + $refU;
    $varV = $V / ( 13 * $L ) + $refV;
    $Y = self::L_to_Y( $L );
    $X = 0 - 9 * $Y * $varU / ( ( $varU - 4 ) * $varV - $varU * $varV );
    $Z = ( 9 * $Y - 15 * $varV * Y - $varV * X ) / ( 3 * $varV );
    return array( $X, $Y, $Z );
  }

  public static function luvToLch( $tuple ) {
    $L = $tuple[0];
    $U = $tuple[1];
    $V = $tuple[2];

    $C = sqrt( pow( $U, 2 ) + pow( $V, 2 ) );
    // Greys: disambiguate hue
    if ( $C < 0.00000001 ) {
      $H = 0;
    } else {
      $Hrad = atan2( $V, $U );
      $H = $Hrad * 360 / 2 / M_PI;
      if ( $H < 0 ) {
        $H = 360 + $H;
      }
    }
    return array( $L, $C, $H );
  }

  public static function lchToLuv( $tuple ) {
    $L = $tuple[0];
    $C = $tuple[1];
    $H = $tuple[2];

    $Hrad = $H / 360 * 2 * M_PI;
    $U = cos( $Hrad ) * $C;
    $V = sin( $Hrad ) * $C;
    return array( $L, $U, $V );
  }

  public static function huslToLch( $tuple ) {
    $H = $tuple[0];
    $S = $tuple[1];
    $L = $tuple[2];
    // White and black: disambiguate chroma

    if ( $L > 99.9999999 || $L < 0.00000001 ) {
      $C = 0;
    } else {
      $max = self::maxChromaForLH( $L, $H );
      $C = $max / 100 * $S;
    }
    return array( $L, $C, $H );
  }

  public static function lchToHusl( $tuple ) {
    $L = $tuple[0];
    $C = $tuple[1];
    $H = $tuple[2];
    // White and black: disambiguate saturation

    if ( $L > 99.9999999 || $L < 0.00000001 ) {
      $S = 0;
    } else {
      $max = self::maxChromaForLH( $L, $H );
      $S = $C / $max * 100;
    }
    return array( $H, $S, $L );
  }

  //# PASTEL HUSL

  public static function huslpToLch( $tuple ) {
    $H = $tuple[0];
    $S = $tuple[1];
    $L = $tuple[2];

    // White and black: disambiguate chroma
    if ( $L > 99.9999999 || $L < 0.00000001) {
      $C = 0;
    } else {
      $max = self::maxSafeChromaForL( $L );
      $C = $max / 100 * S;
    }
    return array( $L, $C, $H );
  }

  public static function lchToHuslp( $tuple ) {
    $L = $tuple[0];
    $C = $tuple[1];
    $H = $tuple[2];
    // White and black: disambiguate saturation

    if ( $L > 99.9999999 || $L < 0.00000001) {
      $S = 0;
    } else {
      $max = self::maxSafeChromaForL( $L );
      $S = $C / $max * 100;
    }
    return array( $H, $S, $L );
  }

  // From https://gist.github.com/Pushplaybang/5432844
  public static function rgb2Hex( $rgb ) {
     $hex = "#";
     $hex .= str_pad( dechex( $rgb[0] ), 2, "0", STR_PAD_LEFT);
     $hex .= str_pad( dechex( $rgb[1] ), 2, "0", STR_PAD_LEFT);
     $hex .= str_pad( dechex( $rgb[2] ), 2, "0", STR_PAD_LEFT);

     return $hex; // returns the hex value including the number sign (#)
  }

  public static function hexTorgb( $hex ) {
     $hex = str_replace("#", "", $hex);

     if( strlen( $hex ) == 3 ) {
        $r = hexdec( substr( $hex,0,1 ).substr( $hex,0,1 ) );
        $g = hexdec( substr( $hex,1,1 ).substr( $hex,1,1 ) );
        $b = hexdec( substr( $hex,2,1 ).substr( $hex,2,1 ) );
     } else {
        $r = hexdec( substr( $hex,0,2 ) );
        $g = hexdec( substr( $hex,2,2 ) );
        $b = hexdec( substr( $hex,4,2 ) );
     }
     $rgb = array( $r, $g, $b );

     return $rgb; // returns an array with the rgb values
  }

  // Helper functions

  public static function lchToRgb( $tuple ) {
    return self::xyzToRgb( self::luvToXyz( self::lchToLuv( $tuple ) ) );
  }
  public static function rgbToLch( $tuple ) {
    return self::luvToLch( self::xyzToLuv( self::rgbToXyz( $tuple ) ) );
  }
  public static function huslToRgb( $tuple ) {
    return self::lchToRgb( self::huslToLch( $tuple ) );
  }
  public static function rgbToHusl( $tuple ) {
    return self::lchToHusl( self::rgbToLch( $tuple ) );
  }
  public static function huslpToRgb( $tuple ) {
    return self::lchToRgb( self::huslpToLch( $tuple ) );
  }
  public static function rgbToHuslp( $tuple ) {
    return self::lchToHuslp( self::rgbToLch( $tuple ) );
  }

  public static function fromRGB( $R, $G, $B ) {
    $rgb = array( $R, $G, $B );
    return self::rgbToHusl( $rgb );
  }
  public static function fromHex( $hex ) {
    return self::rgbToHusl( self::hexToRgb( $hex ) );
  }
  public static function toRGB( $H, $S, $L ) {
    $husl = array( $H, $S, $L );
    return self::huslToRgb( $husl );
  }
  public static function toHex(H, S, L) {
    $husl = array( $H, $S, $L );
    return self::rgbToHex( self::huslToRgb( $husl ) );
  }
  public static function p_toRGB( $H, $S, $L ) {
    $husl = array( $H, $S, $L );
    return self::xyzToRgb( self::luvToXyz( self::lchToLuv( self::huslpToLch( $husl ) ) ) );
  }
  public static function p_toHex( $H, $S, $L ) {
    $husl = array( $H, $S, $L );
    return self::rgbToHex( self::xyzToRgb( self::luvToXyz( self::lchToLuv( self::huslpToLch( $husl ) ) ) ) );
  }
  public static function p_fromRGB( $R, $G, $B ) {
    $rgb = array( $R, $G, $B );
    return self::lchToHuslp( self::luvToLch( self::xyzToLuv( self::rgbToXyz( $rgb ) ) ) );
  }
  public static function p_fromHex( $hex ) {
    return self::lchToHuslp( self::luvToLch( self::xyzToLuv( self::rgbToXyz( self::hexToRgb( $hex ) ) ) ) );
  }

}

// Initialize static class
HUSL::initialize();

?>