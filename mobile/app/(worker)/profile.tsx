import { Pressable, StyleSheet, Text, View } from "react-native";
import { useAuth } from "../../src/auth/AuthProvider";
import { Screen } from "../../src/components/Screen";

export default function WorkerProfileScreen() {
  const { user, logout } = useAuth();
  return <Screen><View style={styles.hero}><Text style={styles.eyebrow}>FIELD WORKER</Text><Text style={styles.title}>{user?.name || "Worker"}</Text><Text style={styles.email}>{user?.email}</Text></View><View style={styles.card}><Text style={styles.cardTitle}>Your mobile workspace</Text><Text style={styles.body}>Use Assignments for work allocated by an administrator, Community Feed to understand nearby context, and City Pulse to inspect issue density on the map.</Text><Text style={styles.meta}>City: {user?.city || "Not set"} · Ward: {user?.ward_id ?? "Not set"}</Text></View><Pressable onPress={() => void logout()} style={styles.button}><Text style={styles.buttonText}>Sign out</Text></Pressable></Screen>;
}

const styles = StyleSheet.create({
  hero: { padding: 22, borderRadius: 22, backgroundColor: "#312e81", gap: 7 },
  eyebrow: { color: "#c7d2fe", fontSize: 11, fontWeight: "900", letterSpacing: 1.1 },
  title: { color: "#fff", fontSize: 28, fontWeight: "900" },
  email: { color: "#e0e7ff" },
  card: { padding: 17, borderRadius: 18, backgroundColor: "#fff", borderWidth: 1, borderColor: "#e8eaf0", gap: 10 },
  cardTitle: { color: "#111827", fontSize: 17, fontWeight: "900" },
  body: { color: "#596273", lineHeight: 21 },
  meta: { color: "#7a8494", fontSize: 12, fontWeight: "700" },
  button: { minHeight: 48, borderRadius: 13, alignItems: "center", justifyContent: "center", backgroundColor: "#fff", borderWidth: 1, borderColor: "#fca5a5" },
  buttonText: { color: "#b42318", fontWeight: "900" },
});
