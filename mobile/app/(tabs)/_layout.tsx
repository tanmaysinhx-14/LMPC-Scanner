import { Tabs } from "expo-router";
import { Ionicons } from "@expo/vector-icons";
import { ActivityIndicator, StyleSheet, Text, View } from "react-native";
import { Redirect } from "expo-router";
import { useAuth } from "../../src/auth/AuthProvider";
import { colors } from "../../src/theme";

export default function TabLayout() {
  const { ready, user } = useAuth();
  if (!ready) return <View style={styles.loading}><ActivityIndicator color="#4f46e5" /><Text style={styles.loadingText}>Loading your workspace…</Text></View>;
  if (!user) return <Redirect href="/login" />;
  if (user.role === "worker") return <Redirect href="/(worker)" />;
  if (user.role === "admin") return <Redirect href="/unsupported" />;
  return (
    <Tabs screenOptions={{
      headerShown: false,
      tabBarActiveTintColor: colors.primary,
      tabBarInactiveTintColor: "#8995aa",
      tabBarLabelStyle: { fontWeight: "800", fontSize: 11 },
      tabBarStyle: { height: 70, paddingTop: 7, paddingBottom: 9, borderTopColor: colors.line, backgroundColor: "#ffffff", elevation: 12, shadowColor: colors.primaryDark, shadowOpacity: 0.08, shadowRadius: 12 },
    }}>
      <Tabs.Screen name="index" options={{ title: "Home", tabBarIcon: ({ color, size }) => <Ionicons name="home-outline" color={color} size={size} /> }} />
      <Tabs.Screen name="feed" options={{ title: "Feed", tabBarIcon: ({ color, size }) => <Ionicons name="newspaper-outline" color={color} size={size} /> }} />
      <Tabs.Screen name="pulse" options={{ title: "City Pulse", tabBarIcon: ({ color, size }) => <Ionicons name="pulse-outline" color={color} size={size} /> }} />
      <Tabs.Screen name="report" options={{ title: "Report", tabBarIcon: ({ color, size }) => <Ionicons name="add-circle-outline" color={color} size={size} /> }} />
    </Tabs>
  );
}

const styles = StyleSheet.create({ loading: { flex: 1, alignItems: "center", justifyContent: "center", gap: 10, backgroundColor: colors.background }, loadingText: { color: colors.muted, fontWeight: "700" } });
