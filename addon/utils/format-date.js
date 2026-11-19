const FORMAT = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false });

/**
 * "19 Nov 2026, 09:05" in the browser's time zone. The server stores UTC.
 */
export default function formatDate(value) {
    if (!value) {
        return null;
    }

    return FORMAT.format(new Date(value)).replace(' at ', ', ');
}
