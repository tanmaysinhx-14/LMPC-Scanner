import { Link, useRouter } from "expo-router";
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from "react-native";
import { useQuery } from "@tanstack/react-query";
import { civicConnectApi } from "../../src/api/client";
import { useAuth } from "../../src/auth/AuthProvider";
import { Screen } from "../../src/components/Screen";
import { StatusView } from "../../src/components/StatusView";
import { IssueCard } from "../../src/components/IssueCard";
import { colors, radii, shadows } from "../../src/theme";

export default function OverviewScreen() {
  const router = useRouter();
  const { user, logout } = useAuth();
  const dashboard = useQuery({ queryKey: ["citizen-dashboard"], queryFn: civicConnectApi.getCitizenDashboard, refetchInterval: 5000, refetchIntervalInBackground: false });

  if (dashboard.isLoading) {
    return <Screen><ActivityIndicator size="large" color="#4f46e5" /></Screen>;
  }

  if (dashboard.isError || !dashboard.data) {
    return <Screen><StatusView message={dashboard.error?.message ?? "The backend returned no dashboard data."} onRetry={() => void dashboard.refetch()} /></Screen>;
  }

  const data = dashboard.data.data;
  const stats = data.stats;

  return (
    <Screen refreshing={dashboard.isRefetching} onRefresh={() => void dashboard.refetch()}>
      <View style={styles.hero}><View style={styles.heroTop}><View><Text style={styles.eyebrow}>CITIZEN HOME</Text><Text style={styles.greeting}>Hi, {user?.name?.split(" ")[0] || "there"}</Text></View><Pressable onPress={() => void logout()}><Text style={styles.logout}>Sign out</Text></Pressable></View><Text style={styles.title}>Help your city move forward.</Text><Text style={styles.subtitle}>Report a problem, follow its assignment, and confirm when the resolution is genuinely complete.</Text><Pressable onPress={() => router.push("/(tabs)/report")} style={styles.heroButton}><Text style={styles.heroButtonText}>Report an issue</Text></Pressable></View>

      <View style={styles.grid}>
        <View style={styles.stat}><Text style={styles.number}>{stats.total_reports}</Text><Text style={styles.label}>My reports</Text></View>
        <View style={styles.stat}><Text style={styles.number}>{stats.active}</Text><Text style={styles.label}>In progress</Text></View>
        <View style={styles.stat}><Text style={[styles.number, styles.resolved]}>{stats.resolved}</Text><Text style={styles.label}>Resolved</Text></View>
      </View>

      <View style={styles.rank}><Text style={styles.rankTitle}>Your civic contribution</Text><Text style={styles.rankText}>Community rank #{data.rank} · {stats.upvotes} total community upvotes on your reports.</Text></View>

      <View style={styles.sectionHeader}><View><Text style={styles.sectionEyebrow}>MY ACTIVITY</Text><Text style={styles.sectionTitle}>Track your reports</Text></View><Pressable onPress={() => router.push("/(tabs)/feed")}><Text style={styles.link}>Community feed</Text></Pressable></View>
      {data.activity.length ? data.activity.slice(0, 3).map((issue) => <IssueCard key={issue.id} issue={issue} onPress={() => router.push(`/issue/${issue.id}`)} />) : <Text style={styles.empty}>Your submitted reports will appear here with their live assignment and resolution status.</Text>}

      <View style={styles.sectionHeader}><View><Text style={styles.sectionEyebrow}>NEARBY SIGNALS</Text><Text style={styles.sectionTitle}>What the community sees</Text></View><Link href="/(tabs)/pulse" asChild><Pressable><Text style={styles.link}>Open pulse</Text></Pressable></Link></View>
      {data.community.items.map((issue) => <IssueCard key={issue.id} issue={issue} onPress={() => router.push(`/issue/${issue.id}`)} />)}
    </Screen>
  );
}

const styles = StyleSheet.create({
  hero: { padding: 21, borderRadius: 24, backgroundColor: colors.primaryDark, gap: 12, ...shadows.card },
  heroTop: { flexDirection: "row", justifyContent: "space-between", alignItems: "flex-start" },
  eyebrow: { color: "#aebcfb", fontSize: 11, fontWeight: "900", letterSpacing: 1.2 },
  greeting: { color: "#fff", fontSize: 16, fontWeight: "900", marginTop: 4 },
  logout: { color: "#dbe3ff", fontSize: 12, fontWeight: "800" },
  title: { color: "#fff", fontSize: 29, lineHeight: 34, fontWeight: "900", letterSpacing: -0.8 },
  subtitle: { color: "#e0e7ff", fontSize: 15, lineHeight: 22 },
  heroButton: { alignSelf: "flex-start", paddingVertical: 11, paddingHorizontal: 14, borderRadius: radii.control, backgroundColor: "#7de0d1" },
  heroButtonText: { color: "#123545", fontWeight: "900", fontSize: 12 },
  grid: { flexDirection: "row", gap: 10 },
  stat: { flex: 1, padding: 14, borderRadius: radii.card, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.line, gap: 4, ...shadows.card },
  number: { color: colors.ink, fontSize: 24, fontWeight: "900" },
  resolved: { color: colors.success },
  label: { color: colors.muted, fontSize: 11, fontWeight: "800" },
  rank: { padding: 16, borderRadius: radii.card, backgroundColor: colors.accentSoft, borderWidth: 1, borderColor: "#b8e8df", gap: 5 },
  rankTitle: { color: colors.accentDark, fontWeight: "900" },
  rankText: { color: colors.accentDark, fontSize: 12, lineHeight: 18 },
  sectionHeader: { flexDirection: "row", alignItems: "flex-end", justifyContent: "space-between", gap: 8, marginTop: 3 },
  sectionEyebrow: { color: colors.primary, fontSize: 10, fontWeight: "900", letterSpacing: 1 },
  sectionTitle: { color: colors.ink, fontSize: 19, fontWeight: "900", marginTop: 3 },
  link: { color: colors.primaryDark, fontSize: 12, fontWeight: "900" },
  empty: { padding: 20, borderRadius: radii.card, color: colors.muted, backgroundColor: colors.surface, textAlign: "center", lineHeight: 19 },
});
