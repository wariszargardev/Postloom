export type ZonedParts = { date: string; time: string };

const pad = (value: number): string => value.toString().padStart(2, '0');

/**
 * The calendar date ("YYYY-MM-DD") and wall-clock time ("HH:mm") of an instant in a timezone.
 */
export function zonedParts(instant: Date, timeZone: string): ZonedParts {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(instant)
            .map((part) => [part.type, part.value]),
    );

    return {
        date: `${parts.year}-${parts.month}-${parts.day}`,
        time: `${parts.hour}:${parts.minute}`,
    };
}

/**
 * The instant that a wall-clock date and time in a timezone refers to.
 */
export function zonedToInstant(
    date: string,
    time: string,
    timeZone: string,
): Date | null {
    const [year, month, day] = date.split('-').map(Number);
    const [hour, minute] = time.split(':').map(Number);

    if ([year, month, day, hour, minute].some((value) => Number.isNaN(value))) {
        return null;
    }

    const asIfUtc = Date.UTC(year, month - 1, day, hour, minute);
    const offset = (instant: number): number => {
        const shown = zonedParts(new Date(instant), timeZone);
        const [shownYear, shownMonth, shownDay] = shown.date
            .split('-')
            .map(Number);
        const [shownHour, shownMinute] = shown.time.split(':').map(Number);

        return (
            Date.UTC(
                shownYear,
                shownMonth - 1,
                shownDay,
                shownHour,
                shownMinute,
            ) - instant
        );
    };

    const firstGuess = asIfUtc - offset(asIfUtc);

    return new Date(asIfUtc - offset(firstGuess));
}

export function addDaysToDate(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const shifted = new Date(Date.UTC(year, month - 1, day + days));

    return `${shifted.getUTCFullYear()}-${pad(shifted.getUTCMonth() + 1)}-${pad(shifted.getUTCDate())}`;
}

export function describeFromNow(instant: Date, now: Date = new Date()): string {
    const totalMinutes = Math.round(
        (instant.getTime() - now.getTime()) / 60000,
    );

    if (totalMinutes < 1) {
        return totalMinutes < 0
            ? 'This time has passed'
            : 'Goes out in under a minute';
    }

    const days = Math.floor(totalMinutes / 1440);
    const hours = Math.floor((totalMinutes % 1440) / 60);
    const minutes = totalMinutes % 60;
    const pieces = [
        days > 0 ? `${days} d` : '',
        hours > 0 ? `${hours} h` : '',
        minutes > 0 ? `${minutes} min` : '',
    ].filter(Boolean);

    return `Goes out in ${pieces.join(' ')}`;
}
