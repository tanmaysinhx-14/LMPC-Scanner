import { Redirect } from "expo-router";
import { ActivityIndicator, StyleSheet, Text, View } from "react-native";
import { useAuth } from "../src/auth/AuthProvider";

export default function Index() {
  const { ready, user } = useAuth();
  if (!ready) {
    return <View style={styles.loading}><ActivityIndicator size="large" color="#4f46e5" /><Text style={styles.loadingText}>Opening CivicConnect…</Text></View>;
  }
  if (!user) return <Redirect href="/login" />;
  if (user.role === "worker") return <Redirect href="/(worker)" />;
  if (user.role === "admin") return <Redirect href="/unsupported" />;
  return <Redirect href="/(tabs)" />;
}

const styles = StyleSheet.create({
  loading: { flex: 1, alignItems: "center", justifyContent: "center", gap: 12, backgroundColor: "#f6f7fb" },
  loadingText: { color: "#596273", fontWeight: "700" },
});
