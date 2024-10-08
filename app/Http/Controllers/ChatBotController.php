<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Rentals;
use App\Models\Books;
use App\Models\Movies;

class ChatBotController extends Controller
{
    /**
     * @OA\Post(
     *     path="/chatbot",
     *     summary="Chat with the AI-powered assistant",
     *     tags={"Chatbot"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", description="User's message to the chatbot")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Chatbot response",
     *         @OA\JsonContent(type="string")
     *     )
     * )
     */
    public function chatbot(Request $request)
    {
        $user = Auth::user();
        $message = strtolower($request->input('message'));

        if ($this->containsAny($message, ['due', 'rentals', 'overdue'])) {
            return $this->getDueRentals($user->user_id);
        } elseif ($this->containsAny($message, ['available books'])) {
            return $this->getAvailableBooks();
        } elseif ($this->containsAny($message, ['available movies'])) {
            return $this->getAvailableMovies();
        } elseif ($this->containsAny($message, ['available', 'to rent'])) {
            return $this->checkAvailabilityByTitle($message);
        } else {
            return response()->json(['response' => "I'm here to assist with questions about due rentals, available books, available movies, or specific item availability."], 200);
        }
    }

    private function getDueRentals($userId)
    {
        $dueRentals = Rentals::where('renter_id', $userId)
                             ->where('returned', false)
                             ->with(['books', 'movies'])
                             ->get();

        if ($dueRentals->isEmpty()) {
            return response()->json(['response' => "You have no due rentals."], 200);
        }

        $dueItems = [];
        foreach ($dueRentals as $rental) {
            if ($rental->book_id) {
                $dueItems[] = "Book: " . $rental->books->title;
            } elseif ($rental->movie_id) {
                $dueItems[] = "Movie: " . $rental->movies->title;
            }
        }

        return response()->json(['response' => "You have due rentals: " . implode(', ', $dueItems)], 200);
    }

    private function getAvailableBooks()
    {
        $availableBooks = Books::where('availability', 1)->pluck('title')->toArray();

        if (empty($availableBooks)) {
            return response()->json(['response' => "No books are currently available."], 200);
        }

        return response()->json(['response' => "Available books: " . implode(', ', $availableBooks)], 200);
    }

    private function getAvailableMovies()
    {
        $availableMovies = Movies::where('availability', 1)->pluck('title')->toArray();

        if (empty($availableMovies)) {
            return response()->json(['response' => "No movies are currently available."], 200);
        }

        return response()->json(['response' => "Available movies: " . implode(', ', $availableMovies)], 200);
    }

    private function checkAvailabilityByTitle($message)
    {
        // Extract potential title from the message
        $title = $this->extractTitle($message);

        // Check exact match in movies
        $movie = Movies::where('title', 'like', '%' . $title . '%')->where('availability', 1)->first();
        if ($movie) {
            return response()->json(['response' => "Yes, the movie '$movie->title' is available to rent."], 200);
        }

        // Check exact match in books
        $book = Books::where('title', 'like', '%' . $title . '%')->where('availability', 1)->first();
        if ($book) {
            return response()->json(['response' => "Yes, the book '$book->title' is available to rent."], 200);
        }

        // No exact match found, try approximate match
        $approximateMovie = $this->approximateTitleMatch($title, 'movies');
        if ($approximateMovie) {
            return response()->json(['response' => "Did you mean '$approximateMovie->title'? It is available to rent."], 200);
        }

        $approximateBook = $this->approximateTitleMatch($title, 'books');
        if ($approximateBook) {
            return response()->json(['response' => "Did you mean '$approximateBook->title'? It is available to rent."], 200);
        }

        return response()->json(['response' => "Sorry, we couldn't find an item close to '$title' available for rent at this time."], 200);
    }

    private function approximateTitleMatch($title, $type)
    {
        $model = $type === 'movies' ? Movies::class : Books::class;
        $titles = $model::where('availability', 1)->pluck('title')->toArray();
        $closestTitle = null;
        $highestSimilarity = 0;

        foreach ($titles as $itemTitle) {
            similar_text($title, $itemTitle, $percent);
            if ($percent > $highestSimilarity && $percent > 70) { // Adjust threshold as needed
                $highestSimilarity = $percent;
                $closestTitle = $itemTitle;
            }
        }

        return $closestTitle ? $model::where('title', $closestTitle)->first() : null;
    }

    /**
     * Helper function to extract a potential title from the user's message
     */
    private function extractTitle($message)
    {
        // Remove common filler words and clean the input for the title
        $title = str_replace(['is', 'available', 'to rent', '?'], '', $message);
        return trim($title);
    }

    private function containsAny($message, $keywords)
    {
        foreach ($keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }
}
