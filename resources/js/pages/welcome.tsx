import { Head, useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { affiliate } from '@/routes';

type LeaderboardEntry = {
    id: number;
    firstName: string | null;
    lastName: string | null;
    company: string | null;
    totalCommission: number;
};

const commissionFormatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

function formatCommission(commissionInCents: number): string {
    return commissionFormatter.format(commissionInCents / 100);
}

function displayName(entry: LeaderboardEntry): string {
    const fullName = [entry.firstName, entry.lastName]
        .filter(Boolean)
        .join(' ');

    return fullName || entry.company || `Affiliate #${entry.id}`;
}

export default function Welcome() {
    const [hasFailed, setHasFailed] = useState(false);
    const { submit, processing, response } = useHttp<
        Record<string, never>,
        LeaderboardEntry[]
    >(affiliate(), {});

    function loadLeaderboard(): void {
        setHasFailed(false);
        submit().catch(() => setHasFailed(true));
    }

    useEffect(loadLeaderboard, []);

    return (
        <>
            <Head title="Affiliate Leaderboard" />

            <main className="mx-auto flex min-h-screen max-w-3xl flex-col justify-center px-4 py-12">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-2xl">
                            Affiliate Leaderboard
                        </CardTitle>
                        <CardDescription>
                            Top five affiliates by lifetime total commission.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        {hasFailed ? (
                            <Alert variant="destructive">
                                <AlertTitle>
                                    Could not load the leaderboard
                                </AlertTitle>
                                <AlertDescription>
                                    <p>
                                        The Refersion API did not respond as
                                        expected.
                                    </p>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="mt-2"
                                        onClick={loadLeaderboard}
                                        disabled={processing}
                                    >
                                        Try again
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        ) : processing || response === null ? (
                            <div className="space-y-3" aria-busy="true">
                                {Array.from({ length: 5 }, (_, index) => (
                                    <Skeleton
                                        key={index}
                                        className="h-10 w-full"
                                    />
                                ))}
                            </div>
                        ) : response.length === 0 ? (
                            <p className="text-muted-foreground">
                                No affiliates are marked to display in the
                                leaderboard.
                            </p>
                        ) : (
                            <table className="w-full text-left text-sm">
                                <thead className="border-b text-muted-foreground">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="w-16 py-2 font-medium"
                                        >
                                            Rank
                                        </th>
                                        <th
                                            scope="col"
                                            className="py-2 font-medium"
                                        >
                                            Affiliate
                                        </th>
                                        <th
                                            scope="col"
                                            className="py-2 text-right font-medium"
                                        >
                                            Total commission
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {response.map((entry, index) => (
                                        <tr
                                            key={entry.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-3 font-semibold">
                                                {index + 1}
                                            </td>
                                            <td className="py-3">
                                                <div className="font-medium">
                                                    {displayName(entry)}
                                                </div>
                                                {entry.company &&
                                                    displayName(entry) !==
                                                        entry.company && (
                                                        <div className="text-xs text-muted-foreground">
                                                            {entry.company}
                                                        </div>
                                                    )}
                                            </td>
                                            <td className="py-3 text-right tabular-nums">
                                                {formatCommission(
                                                    entry.totalCommission,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
