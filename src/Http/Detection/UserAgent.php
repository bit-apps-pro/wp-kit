<?php

namespace BitApps\WPKit\Http\Detection;

/**
 * Classifies the visitor's browser and operating system from the User-Agent header.
 */
final class UserAgent
{
    private const BROWSERS = [
        'Opera'             => ['opera', 'opr/'],
        'Edge'              => ['edge', 'edg/'],
        'Chrome'            => ['chrome'],
        'Safari'            => ['safari'],
        'Firefox'           => ['firefox'],
        'Internet Explorer' => ['msie', 'trident/7'],
    ];

    private const BOTS = [
        'Googlebot'      => ['google'],
        'Bingbot'        => ['bing'],
        'Yahoo! Slurp'   => ['slurp'],
        'DuckDuckBot'    => ['duckduckgo'],
        'Baidu'          => ['baidu'],
        'Yandex'         => ['yandex'],
        'Sogou'          => ['sogou'],
        'Exabot'         => ['exabot'],
        'MSN'            => ['msn'],
        'Majestic'       => ['mj12bot'],
        'Ahrefs'         => ['ahrefs'],
        'SEMRush'        => ['semrush'],
        'Moz'            => ['rogerbot', 'dotbot'],
        'Screaming Frog' => ['frog', 'screaming'],
        'Facebook'       => ['facebook'],
        'Pinterest'      => ['pinterest'],
        'Bot'            => ['crawler', 'api', 'spider', 'http', 'bot', 'archive', 'info', 'data'],
    ];

    /**
     * Check device info.
     */
    public static function checkDevice(): string
    {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return '';
        }

        $userAgent = wp_kses(wp_unslash($_SERVER['HTTP_USER_AGENT']), []);

        return self::getBrowserName($userAgent) . '|' . self::getOS($userAgent);
    }

    /**
     * Get browser name.
     *
     * @param string $userAgent $_SERVER['HTTP_USER_AGENT']
     *
     * @see https://stackoverflow.com/questions/18070154/get-operating-system-info
     */
    private static function getBrowserName($userAgent): string
    {
        $userAgent = strtolower((string) $userAgent);
        foreach (self::BROWSERS + self::BOTS as $name => $tokens) {
            foreach ($tokens as $token) {
                if (str_contains($userAgent, $token)) {
                    return $name;
                }
            }
        }

        return 'Other (Unknown)';
    }

    /**
     * Provide Operating System Information of User.
     *
     * @param mixed $userAgent
     *
     * @see https://stackoverflow.com/questions/18070154/get-operating-system-info
     */
    private static function getOS($userAgent): string
    {
        $ros = [
            ['Windows XP', 'Windows XP'],
            ['Windows NT 5.1|Windows NT5.1', 'Windows XP'],
            ['Windows 2000', 'Windows 2000'],
            ['Windows NT 5.0', 'Windows 2000'],
            ['Windows NT 4.0|WinNT4.0', 'Windows NT'],
            ['Windows NT 5.2', 'Windows Server 2003'],
            ['Windows NT 6.0', 'Windows Vista'],
            ['Windows NT 7.0', 'Windows 7'],
            ['Windows CE', 'Windows CE'],
            [
                '(media center pc).([0-9]{1,2}\.[0-9]{1,2})',
                'Windows Media Center',
            ],
            ['(win)([0-9]{1,2}\.[0-9x]{1,2})', 'Windows'],
            ['(win)([0-9]{2})', 'Windows'],
            ['(windows)([0-9x]{2})', 'Windows'],
            ['Windows ME', 'Windows ME'],
            ['Win 9x 4.90', 'Windows ME'],
            ['Windows 98|Win98', 'Windows 98'],
            ['Windows 95', 'Windows 95'],
            ['(windows)([0-9]{1,2}\.[0-9]{1,2})', 'Windows'],
            ['win32', 'Windows'],
            ['(java)([0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,2})', 'Java'],
            ['(Solaris)([0-9]{1,2}\.[0-9x]{1,2}){0,1}', 'Solaris'],
            ['dos x86', 'DOS'],
            ['unix', 'Unix'],
            // Android
            ['SM', 'Samsung'],
            ['HTC', 'HTC'],
            ['LG', 'LG'],
            ['Microsoft', 'Microsoft'],
            ['Pixel', 'Pixel'],
            ['MI', 'Xiaomi'],
            ['Xiaomi', 'Xiaomi'],
            ['Android', 'Android'],
            ['android', 'Android'],

            // iPhone
            ['iPhone', 'iPhone'],

            ['Mac OS X', 'Mac OS X'],
            ['Mac OS X Puma', 'Mac OS X 10.1[^0-9]'],
            ['Mac_PowerPC', 'Macintosh PowerPC'],
            ['(mac|Macintosh)', 'Mac OS'],
            ['(sunos)([0-9]{1,2}\.[0-9]{1,2}){0,1}', 'SunOS'],
            ['(beos)([0-9]{1,2}\.[0-9]{1,2}){0,1}', 'BeOS'],
            ['(risc os)([0-9]{1,2}\.[0-9]{1,2})', 'RISC OS'],
            ['os\/2', 'OS/2'],
            ['freebsd', 'FreeBSD'],
            ['openbsd', 'OpenBSD'],
            ['netbsd', 'NetBSD'],
            ['irix', 'IRIX'],
            ['plan9', 'Plan9'],
            ['osf', 'OSF'],
            ['aix', 'AIX'],
            ['GNU Hurd', 'GNU Hurd'],
            ['(fedora)', 'Linux - Fedora'],
            ['(kubuntu)', 'Linux - Kubuntu'],
            ['(ubuntu)', 'Linux - Ubuntu'],
            ['(debian)', 'Linux - Debian'],
            ['(CentOS)', 'Linux - CentOS'],
            [
                '(Mandriva).([0-9]{1,3}(\.[0-9]{1,3})?(\.[0-9]{1,3})?)',
                'Linux - Mandriva',
            ],
            [
                '(SUSE).([0-9]{1,3}(\.[0-9]{1,3})?(\.[0-9]{1,3})?)',
                'Linux - SUSE',
            ],
            ['(Dropline)', 'Linux - Slackware (Dropline GNOME)'],
            ['(ASPLinux)', 'Linux - ASPLinux'],
            ['(Red Hat)', 'Linux - Red Hat'],
            ['(linux)', 'Linux'],
            ['(amigaos)([0-9]{1,2}\.[0-9]{1,2})', 'AmigaOS'],
            ['amiga-aweb', 'AmigaOS'],
            ['amiga', 'Amiga'],
            ['AvantGo', 'PalmOS'],
            ['(webtv)/([0-9]{1,2}\.[0-9]{1,2})', 'WebTV'],
            ['Dreamcast', 'Dreamcast OS'],
            ['GetRight', 'Windows'],
            ['go!zilla', 'Windows'],
            ['gozilla', 'Windows'],
            ['gulliver', 'Windows'],
            ['ia archiver', 'Windows'],
            ['NetPositive', 'Windows'],
            ['mass downloader', 'Windows'],
            ['microsoft', 'Windows'],
            ['offline explorer', 'Windows'],
            ['teleport', 'Windows'],
            ['web downloader', 'Windows'],
            ['webcapture', 'Windows'],
            ['webcollage', 'Windows'],
            ['webcopier', 'Windows'],
            ['webstripper', 'Windows'],
            ['webzip', 'Windows'],
            ['wget', 'Windows'],
            ['Java', 'Unknown'],
            ['flashget', 'Windows'],
            ['MS FrontPage', 'Windows'],
            ['(msproxy)/([0-9]{1,2}.[0-9]{1,2})', 'Windows'],
            ['(msie)([0-9]{1,2}.[0-9]{1,2})', 'Windows'],
            ['libwww-perl', 'Unix'],
            ['UP.Browser', 'Windows CE'],
            ['NetAnts', 'Windows'],
            ['Android', 'Android'],
        ];
        foreach ($ros as [$pattern, $name]) {
            if (preg_match('~' . $pattern . '~i', (string) $userAgent) === 1) {
                return $name;
            }
        }

        return '';
    }
}
